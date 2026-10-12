<?php

namespace App\Services\Common;

use App\Drivers\DriverManager;
use App\Drivers\Pay\Dto\RefundRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\PayTrade;
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use App\Support\OpenEvents;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * 售后/退款的唯一流转入口（C 端申请、后台审核与退款都调这里）。
 *
 * 流程：
 *   仅退款  ：待审核 → 退款中 → 已完成
 *   退货退款：待审核 → 待买家退货 → 待确认收货 → 退款中 → 已完成
 *   待审核时商家可拒绝；待审核、待买家退货时买家可撤销。
 *
 * 规则：
 * - 整单退款（order_item_id=0）只用于还没发过货、也没有过售后的已支付订单，退全部实付（含运费）。
 * - 单品售后按数量申请，可退上限 = 该数量按商品金额占比分摊的实付（不含运费），且不超过订单剩余可退金额；
 *   商家审核时可以改退款金额（不超过订单剩余可退金额）。
 * - 有进行中的售后时订单为“售后中”，全部处理完后回到应有状态；商品全部退完则订单关闭。
 * - 库存：未发货的退款、退货退款完成时回补库存；已发货的仅退款不回补。
 * - 积分：下单抵扣的积分在商品全部退完（订单关闭）时一次性退还；部分退款不退积分。
 * - 资金：在线支付的订单调用支付渠道原路退回；线下收款的订单只能登记“线下已退款”。
 */
class OrderRefundService
{
    public function __construct(
        private OrderStatusService $status,
        private OrderStockService $stock,
        private ConfigService $config,
        private DriverManager $drivers,
        private OrderPointsService $points,
        private DistributionService $distribution,
        private NotifyService $notify,
        private OpenWebhookService $webhooks,
    ) {}

    // ------------------------------------------------------------------ 可退信息
    /**
     * 订单当前可申请的售后项。$byAdmin 为 true 时是商家代买家发起，不受售后申请期限限制。
     * @return array{whole_order:array{allowed:bool,amount:string},items:array<int,array<string,mixed>>,reason:string}
     */
    public function refundable(Order $order, bool $byAdmin = false): array
    {
        $why = $this->notApplicableReason($order, $byAdmin);
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get();
        $busy = $this->activeQty((int) $order->id);
        $remain = $this->remainAmount($order);
        $untouched = !OrderRefund::query()->where('order_id', $order->id)->whereIn('status', [...OrderRefund::ACTIVE, OrderRefund::DONE])->exists();

        $out = [];
        foreach ($items as $it) {
            $avail = $why === null ? max((int) $it->qty - (int) $it->refund_qty - ($busy[$it->id] ?? 0), 0) : 0;
            $out[] = [
                'item_id' => (int) $it->id, 'title' => $it->title, 'spec_text' => $it->spec_text, 'image' => $it->image,
                'price' => (string) $it->price, 'qty' => (int) $it->qty, 'refunded_qty' => (int) $it->refund_qty,
                'available_qty' => $avail, 'can_return' => $avail > 0 && (int) $it->ship_status === 1,
                'max_amount' => $avail > 0 ? $this->itemMax($order, $it, $avail, $remain) : '0.00',
            ];
        }

        $whole = $why === null && $untouched && (int) $order->status === OrderStatus::TO_SHIP
            && $items->every(fn ($it) => (int) $it->ship_status === 0);

        return [
            'whole_order' => ['allowed' => $whole, 'amount' => $whole ? (string) $order->pay_amount : '0.00'],
            'items' => $out, 'reason' => (string) $why,
        ];
    }

    // ------------------------------------------------------------------ 买家：申请 / 撤销 / 填写退货物流
    /**
     * 买家申请售后。$adminId > 0 表示商家代买家发起：不校验订单归属、不受售后申请期限限制，其余规则（数量、金额上限、整单条件）相同。
     * @param array{order_item_id?:int,qty?:int,type?:int,reason:string,images?:string[],amount?:string|null} $d
     */
    public function apply(int $orderId, int $memberId, array $d, int $adminId = 0): OrderRefund
    {
        return DB::transaction(function () use ($orderId, $memberId, $d, $adminId) {
            $byAdmin = $adminId > 0;
            $order = Order::query()->lockForUpdate()->find($orderId) ?? throw new BusinessException('订单不存在');
            if ($byAdmin) {
                $memberId = (int) $order->member_id;
            } elseif ((int) $order->member_id !== $memberId) {
                throw new BusinessException('订单不存在');
            }
            if (($why = $this->notApplicableReason($order, $byAdmin)) !== null) {
                throw new BusinessException($why);
            }

            $itemId = (int) ($d['order_item_id'] ?? 0);
            $type = (int) ($d['type'] ?? OrderRefund::TYPE_REFUND);
            $remain = $this->remainAmount($order);

            if ($itemId === 0) {
                if (!$this->refundable($order, $byAdmin)['whole_order']['allowed']) {
                    throw new BusinessException('该订单不能整单退款，请按商品申请售后');
                }
                if ($type !== OrderRefund::TYPE_REFUND) {
                    throw new BusinessException('未发货的订单只能申请仅退款');
                }
                $qty = 0;
                $max = (string) $order->pay_amount;
            } else {
                $item = OrderItem::query()->where('order_id', $orderId)->lockForUpdate()->find($itemId) ?? throw new BusinessException('订单商品不存在');
                $qty = (int) ($d['qty'] ?? 0);
                $avail = (int) $item->qty - (int) $item->refund_qty - ($this->activeQty($orderId)[$itemId] ?? 0);
                if ($qty < 1 || $qty > $avail) {
                    throw new BusinessException($avail > 0 ? "该商品最多还能申请 {$avail} 件" : '该商品已全部申请过售后');
                }
                if ($type === OrderRefund::TYPE_RETURN && (int) $item->ship_status !== 1) {
                    throw new BusinessException('该商品还没有发货，请选择仅退款');
                }
                $max = $this->itemMax($order, $item, $qty, $remain);
            }

            $amount = isset($d['amount']) && $d['amount'] !== '' ? Money::round(number_format((float) $d['amount'], 4, '.', ''), 2) : $max;
            if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, $max, 2) > 0) {
                throw new BusinessException("退款金额必须大于 0 且不超过 {$max} {$order->currency}");
            }

            $refund = OrderRefund::query()->create([
                'refund_no' => $this->makeRefundNo(), 'order_id' => $orderId, 'order_item_id' => $itemId, 'member_id' => $memberId,
                'type' => $type, 'reason' => mb_substr(($byAdmin ? '商家发起：' : '') . trim((string) $d['reason']), 0, 255),
                'images' => json_encode(array_values((array) ($d['images'] ?? [])), JSON_UNESCAPED_SLASHES),
                'qty' => $qty, 'currency' => $order->currency, 'refund_amount' => $amount, 'status' => OrderRefund::PENDING,
            ]);

            $remark = ($byAdmin ? '商家代买家发起售后' : '买家申请售后') . " {$refund->refund_no}：" . OrderRefund::TYPE_TEXT[$type] . " {$amount} {$order->currency}";
            [$opType, $opId] = $byAdmin ? [OrderStatus::OP_ADMIN, $adminId] : [OrderStatus::OP_MEMBER, $memberId];
            if ((int) $order->status !== OrderStatus::AFTER_SALE) {
                $this->status->transit($order, [OrderStatus::TO_SHIP, OrderStatus::SHIPPED, OrderStatus::FINISHED], OrderStatus::AFTER_SALE, $opType, $opId, $remark);
            } else {
                $this->status->note($order, $opType, $opId, $remark);
            }
            $this->webhooks->refund(OpenEvents::REFUND_CREATED, $refund, $order);

            return $refund;
        });
    }

    /**
     * 商家代买家发起售后，并直接视为审核通过：仅退款进入“退款中”（还要再执行退款），退货退款进入“待买家退货”。
     * @param array{order_item_id?:int,qty?:int,type?:int,reason:string,amount?:string|null} $d
     */
    public function applyByAdmin(int $orderId, int $adminId, array $d): OrderRefund
    {
        return DB::transaction(function () use ($orderId, $adminId, $d) {
            $refund = $this->apply($orderId, 0, ['images' => []] + $d, $adminId);
            $this->approve((int) $refund->id, $adminId);

            return $refund->fresh();
        });
    }

    public function cancel(int $refundId, int $memberId): void
    {
        DB::transaction(function () use ($refundId, $memberId) {
            [$refund, $order] = $this->lock($refundId, $memberId);
            $this->move($refund, [OrderRefund::PENDING, OrderRefund::WAIT_RETURN], OrderRefund::CANCELLED, ['finished_at' => now()]);
            $this->restoreOrder($order, OrderStatus::OP_MEMBER, $memberId, "买家撤销售后 {$refund->refund_no}");
        });
    }

    public function submitReturn(int $refundId, int $memberId, string $express, string $expressNo): void
    {
        DB::transaction(function () use ($refundId, $memberId, $express, $expressNo) {
            [$refund, $order] = $this->lock($refundId, $memberId);
            $this->move($refund, [OrderRefund::WAIT_RETURN], OrderRefund::WAIT_RECEIVE, [
                'return_express' => mb_substr(trim($express), 0, 60), 'return_express_no' => mb_substr(trim($expressNo), 0, 60),
            ]);
            $this->status->note($order, OrderStatus::OP_MEMBER, $memberId, "买家已寄回商品（售后 {$refund->refund_no}）：{$express} {$expressNo}");
        });
    }

    // ------------------------------------------------------------------ 商家：审核 / 确认收货 / 退款
    /** 同意：仅退款进入“退款中”，退货退款进入“待买家退货”。$amount 为空表示按申请金额 */
    public function approve(int $refundId, int $adminId, ?string $amount = null): void
    {
        DB::transaction(function () use ($refundId, $adminId, $amount) {
            [$refund, $order] = $this->lock($refundId);
            $final = (string) $refund->refund_amount;
            if ($amount !== null && $amount !== '') {
                $final = Money::round(number_format((float) $amount, 4, '.', ''), 2);
                // 可退上限：订单剩余可退金额 + 本单已占用的金额
                $cap = bcadd($this->remainAmount($order), (string) $refund->refund_amount, 2);
                if (bccomp($final, '0', 2) <= 0 || bccomp($final, $cap, 2) > 0) {
                    throw new BusinessException("退款金额必须大于 0 且不超过 {$cap} {$order->currency}");
                }
            }
            $to = (int) $refund->type === OrderRefund::TYPE_RETURN ? OrderRefund::WAIT_RETURN : OrderRefund::REFUNDING;
            $this->move($refund, [OrderRefund::PENDING], $to, ['refund_amount' => $final]);
            $this->status->note($order, OrderStatus::OP_ADMIN, $adminId, "同意售后 {$refund->refund_no}，退款金额 {$final} {$order->currency}");
        });
    }

    public function reject(int $refundId, int $adminId, string $reason): void
    {
        DB::transaction(function () use ($refundId, $adminId, $reason) {
            [$refund, $order] = $this->lock($refundId);
            $this->move($refund, [OrderRefund::PENDING], OrderRefund::REJECTED, ['reject_reason' => mb_substr(trim($reason), 0, 255), 'finished_at' => now()]);
            $this->restoreOrder($order, OrderStatus::OP_ADMIN, $adminId, "拒绝售后 {$refund->refund_no}：{$reason}");
            $this->notify->send('refund_rejected', (int) $refund->member_id, [
                'refund_no' => $refund->refund_no, 'order_no' => $order->order_no, 'reject_reason' => trim($reason),
            ]);
        });
    }

    /** 商家确认收到退货 */
    public function confirmReturn(int $refundId, int $adminId): void
    {
        DB::transaction(function () use ($refundId, $adminId) {
            [$refund, $order] = $this->lock($refundId);
            $this->move($refund, [OrderRefund::WAIT_RECEIVE], OrderRefund::REFUNDING);
            $this->status->note($order, OrderStatus::OP_ADMIN, $adminId, "已确认收到退货（售后 {$refund->refund_no}）");
        });
    }

    /**
     * 执行退款。$offline=true 表示款项已通过线下或渠道后台退回，这里只登记，不调用支付渠道。
     * @return array{done:bool,msg:string} done=false 表示渠道受理中，稍后再次执行以查询结果
     */
    public function pay(int $refundId, int $adminId, bool $offline = false): array
    {
        // 先在事务里校验并取出退款所需信息；调用渠道放在事务外，避免长时间持锁
        [$refund, $order, $trade] = DB::transaction(function () use ($refundId) {
            [$refund, $order] = $this->lock($refundId);
            if ((int) $refund->status !== OrderRefund::REFUNDING) {
                throw new BusinessException('售后单当前状态不能退款（' . (OrderRefund::STATUS_TEXT[(int) $refund->status] ?? '') . '）');
            }
            $trade = PayTrade::query()->where('order_id', $order->id)->where('trade_type', 1)->where('status', PayTrade::SUCCESS)->orderByDesc('id')->first();

            return [$refund, $order, $trade];
        });

        if ($offline) {
            $this->finish($refundId, $adminId, 'offline', (string) $refund->refund_no, '线下已退款');

            return ['done' => true, 'msg' => '已登记为线下退款'];
        }
        if (!$trade) {
            throw new BusinessException('该订单没有在线支付记录（线下收款），请线下退款后选择“线下已退款”');
        }

        // 渠道已经受理过（留有渠道退款单号）就只查询结果；否则发起退款
        $driver = $this->drivers->pay((string) $trade->channel);
        $res = (string) $refund->refund_trade_no !== ''
            ? $driver->queryRefund((string) $refund->refund_no, (string) $refund->refund_trade_no)
            : $driver->refund(new RefundRequest(
                refundNo: (string) $refund->refund_no, tradeNo: (string) $trade->trade_no, channelTradeNo: $trade->channelNo(),
                currency: (string) $refund->currency, amount: (string) $refund->refund_amount, totalAmount: (string) $trade->amount,
                reason: (string) $refund->reason,
            ));

        if ($res->success) {
            $this->finish($refundId, $adminId, (string) $trade->channel, $res->channelRefundNo ?: (string) $refund->refund_no, "已原路退回（{$trade->channel}）");

            return ['done' => true, 'msg' => '退款成功，已原路退回'];
        }
        if ($res->pending) {
            OrderRefund::query()->whereKey($refundId)->where('status', OrderRefund::REFUNDING)->update(['refund_trade_no' => $res->channelRefundNo, 'updated_at' => now()]);

            return ['done' => false, 'msg' => '支付渠道已受理，正在处理中；到账后系统会自动完成，也可以稍后再次点击“执行退款”查询结果'];
        }
        // 渠道明确退款失败：清掉渠道退款单号，下次点击会重新发起
        OrderRefund::query()->whereKey($refundId)->where('status', OrderRefund::REFUNDING)->update(['refund_trade_no' => '', 'updated_at' => now()]);
        throw new BusinessException('支付渠道未能完成退款，请到渠道后台核实后重试');
    }

    /**
     * 渠道回调通知“退款成功”时调用：售后单还在“退款中”、金额币种一致就完成它。重复通知无副作用。
     * @return array{0:bool,1:string} [是否处理成功, 说明]
     */
    public function completeByNotify(string $refundNo, string $channel, string $channelRefundNo, string $currency, string $amount): array
    {
        $refund = OrderRefund::query()->where('refund_no', $refundNo)->first();
        if (!$refund) {
            return [false, '未找到售后单：' . $refundNo];
        }
        if ((int) $refund->status === OrderRefund::DONE) {
            return [true, '售后单已完成，忽略重复通知'];
        }
        if ((int) $refund->status !== OrderRefund::REFUNDING) {
            return [false, "售后单 {$refundNo} 当前是“" . (OrderRefund::STATUS_TEXT[(int) $refund->status] ?? '') . '”，渠道却通知退款成功，需人工核对'];
        }
        if (strtoupper($currency) !== strtoupper((string) $refund->currency) || bccomp(Money::round(number_format((float) $amount, 4, '.', ''), 2), (string) $refund->refund_amount, 2) !== 0) {
            return [false, "渠道退款金额 {$amount} {$currency} 与售后单 {$refund->refund_amount} {$refund->currency} 不一致，需人工核对"];
        }
        try {
            $this->finish((int) $refund->id, 0, $channel, $channelRefundNo ?: $refundNo, "渠道通知退款成功（{$channel}）");
        } catch (BusinessException $e) {
            // 和管理员的手动操作撞上了：以最新状态为准
            return [(int) $refund->fresh()->status === OrderRefund::DONE, '售后单状态已变化：' . $e->getMessage()];
        }

        return [true, "售后单 {$refundNo} 已自动完成退款"];
    }

    /** 渠道回调通知“退款失败”：清掉渠道退款单号并在订单日志里留一笔，等人工重新处理 */
    public function failByNotify(string $refundNo, string $channel): array
    {
        $refund = OrderRefund::query()->where('refund_no', $refundNo)->first();
        if (!$refund || (int) $refund->status !== OrderRefund::REFUNDING) {
            return [true, '退款失败通知已记录（售后单不在退款中）'];
        }
        DB::transaction(function () use ($refund, $channel) {
            OrderRefund::query()->whereKey($refund->id)->where('status', OrderRefund::REFUNDING)->update(['refund_trade_no' => '', 'updated_at' => now()]);
            $order = Order::query()->find($refund->order_id);
            if ($order) {
                $this->status->note($order, OrderStatus::OP_SYSTEM, 0, "售后 {$refund->refund_no}：支付渠道（{$channel}）通知退款失败，请重新执行退款或改为线下退款");
            }
        });

        return [true, "售后单 {$refundNo} 渠道退款失败，已提示人工处理"];
    }

    /**
     * 补偿任务：渠道已受理但还没出结果的退款，主动去查一次。返回本次完成的笔数。
     * 单笔出错（渠道报错、明确失败）不影响其它，留给人工处理。
     */
    public function compensate(int $limit = 50): int
    {
        $ids = OrderRefund::query()->where('status', OrderRefund::REFUNDING)->where('refund_trade_no', '!=', '')
            ->where('updated_at', '<=', now()->subMinutes(2))->orderBy('id')->limit($limit)->pluck('id');
        $done = 0;
        foreach ($ids as $id) {
            try {
                if ($this->pay((int) $id, 0)['done']) {
                    $done++;
                } else {
                    // 仍在处理中：刷新时间，避免每次都排在最前面
                    OrderRefund::query()->whereKey($id)->where('status', OrderRefund::REFUNDING)->update(['updated_at' => now()]);
                }
            } catch (\Throwable $e) {
                if (!$e instanceof BusinessException) {
                    report($e);
                }
            }
        }

        return $done;
    }

    // ------------------------------------------------------------------ 内部
    /** 退款到账后的收尾：售后单完成、写退款流水、累计已退数量、回补库存、扣销量、恢复订单状态 */
    private function finish(int $refundId, int $adminId, string $channel, string $channelRefundNo, string $remark): void
    {
        DB::transaction(function () use ($refundId, $adminId, $channel, $channelRefundNo, $remark) {
            [$refund, $order] = $this->lock($refundId);
            $this->move($refund, [OrderRefund::REFUNDING], OrderRefund::DONE, ['refund_trade_no' => $channelRefundNo, 'finished_at' => now()]);

            PayTrade::query()->create([
                'trade_no' => $refund->refund_no, 'order_id' => $order->id, 'member_id' => $order->member_id, 'channel' => $channel,
                'channel_trade_no' => $channelRefundNo, 'trade_type' => 2, 'currency' => $refund->currency, 'amount' => $refund->refund_amount,
                'status' => PayTrade::SUCCESS, 'paid_at' => now(),
            ]);

            $items = OrderItem::query()->where('order_id', $order->id)->lockForUpdate()->get();
            if ((int) $refund->order_item_id === 0) {
                // 整单退款：订单从未发货，全部回补
                foreach ($items as $it) {
                    $this->reduceSales($it, (int) $it->qty - (int) $it->refund_qty);
                    $it->update(['refund_qty' => (int) $it->qty]);
                }
                $this->stock->restore($order->order_no, 3, '售后回补');
            } else {
                $it = $items->firstWhere('id', (int) $refund->order_item_id);
                if ($it) {
                    $qty = (int) $refund->qty;
                    $it->update(['refund_qty' => min((int) $it->refund_qty + $qty, (int) $it->qty)]);
                    $this->reduceSales($it, $qty);
                    if ((int) $refund->type === OrderRefund::TYPE_RETURN || (int) $it->ship_status === 0) {
                        $this->stock->restoreQty($order->order_no, (int) $it->goods_id, (int) $it->sku_id, $qty, '售后回补');
                    }
                }
            }

            // 分销佣金按最新的已退金额重算（商品全部退完则作废）
            $this->distribution->onRefund($order);
            $this->points->clawBack($order, $refund);
            $this->notify->send('refund_done', (int) $refund->member_id, [
                'refund_no' => $refund->refund_no, 'order_no' => $order->order_no,
                'refund_amount' => (string) $refund->refund_amount, 'currency' => $refund->currency,
            ]);

            $this->restoreOrder($order, $adminId > 0 ? OrderStatus::OP_ADMIN : OrderStatus::OP_SYSTEM, $adminId, "售后 {$refund->refund_no} 退款完成：{$refund->refund_amount} {$refund->currency}，{$remark}");
            $this->webhooks->refund(OpenEvents::REFUND_COMPLETED, $refund->refresh(), $order);
        });
    }

    /**
     * 一张售后单结束后（完成/拒绝/撤销）恢复订单状态：还有进行中的售后则保持“售后中”；
     * 否则商品全部退完 → 已关闭；订单已完成过 → 已完成；还有未发货的 → 待发货；都已发货 → 待收货。支付状态按已退金额更新。
     */
    private function restoreOrder(Order $order, int $opType, int $opId, string $remark): void
    {
        $refunded = (string) OrderRefund::query()->where('order_id', $order->id)->where('status', OrderRefund::DONE)->sum('refund_amount');
        $payStatus = match (true) {
            bccomp($refunded, '0', 2) <= 0 => OrderStatus::PAY_PAID,
            bccomp($refunded, (string) $order->pay_amount, 2) >= 0 => OrderStatus::PAY_REFUNDED,
            default => OrderStatus::PAY_PART_REFUND,
        };

        if (OrderRefund::query()->where('order_id', $order->id)->whereIn('status', OrderRefund::ACTIVE)->exists()) {
            Order::query()->whereKey($order->id)->update(['pay_status' => $payStatus]);
            $this->status->note($order, $opType, $opId, $remark);

            return;
        }

        $left = OrderItem::query()->where('order_id', $order->id)->whereColumn('refund_qty', '<', 'qty')->get();
        $extra = ['pay_status' => $payStatus];
        if ($left->isEmpty()) {
            $to = OrderStatus::CLOSED;
            $extra['cancel_reason'] = '商品已全部退款';
            $this->points->giveBack($order, '商品已全部退款，退还抵扣积分');
        } elseif ($order->finished_at) {
            // 已完成的订单（含无需发货的虚拟商品订单）售后结束后仍是已完成
            $to = OrderStatus::FINISHED;
        } elseif ($left->contains(fn ($it) => (int) $it->ship_status === 0)) {
            $to = OrderStatus::TO_SHIP;
        } else {
            $to = OrderStatus::SHIPPED;
            $extra['shipped_at'] = $order->shipped_at ?? now();
        }

        $this->status->transit($order, [OrderStatus::AFTER_SALE], $to, $opType, $opId, $remark, $extra);
    }

    /** 不能申请售后的原因；可以申请返回 null */
    private function notApplicableReason(Order $order, bool $byAdmin = false): ?string
    {
        if (!in_array((int) $order->pay_status, [OrderStatus::PAY_PAID, OrderStatus::PAY_PART_REFUND], true)) {
            return (int) $order->pay_status === OrderStatus::PAY_REFUNDED ? '订单已全额退款' : '订单还没有支付';
        }
        if (!in_array((int) $order->status, [OrderStatus::TO_SHIP, OrderStatus::SHIPPED, OrderStatus::FINISHED, OrderStatus::AFTER_SALE], true)) {
            return '订单当前状态不能申请售后';
        }
        // 售后申请期限只限制买家；商家可以为超期的订单发起售后
        if ($order->finished_at && !$byAdmin) {
            $days = (int) ($this->config->value('trade', 'after_sale_days') ?? 7);
            if ($order->finished_at->copy()->addDays($days)->isPast()) {
                return $days > 0 ? "订单完成已超过 {$days} 天，不能再申请售后" : '订单已完成，不能再申请售后';
            }
        }

        return null;
    }

    /** 订单剩余可退金额 = 实付 - 进行中和已完成的售后金额 */
    private function remainAmount(Order $order): string
    {
        $used = (string) OrderRefund::query()->where('order_id', $order->id)->whereIn('status', [...OrderRefund::ACTIVE, OrderRefund::DONE])->sum('refund_amount');

        return bccomp($used, (string) $order->pay_amount, 2) >= 0 ? '0.00' : bcsub((string) $order->pay_amount, $used, 2);
    }

    /** 进行中的售后占用的数量 @return array<int,int> order_item_id => qty */
    private function activeQty(int $orderId): array
    {
        return OrderRefund::query()->where('order_id', $orderId)->where('order_item_id', '>', 0)->whereIn('status', OrderRefund::ACTIVE)
            ->groupBy('order_item_id')->selectRaw('order_item_id, sum(qty) as q')->pluck('q', 'order_item_id')->map(fn ($v) => (int) $v)->all();
    }

    /** 某商品行退 $qty 件的可退上限：按商品金额占比分摊实付（不含运费），不超过订单剩余可退金额 */
    private function itemMax(Order $order, OrderItem $item, int $qty, string $remain): string
    {
        return self::share((string) $item->price, $qty, (string) $order->goods_amount, (string) $order->pay_amount, TaxService::shippingPaid($order), $remain);
    }

    /** 纯计算：单价 × 数量 占商品总额的比例 × 商品部分的实付（实付 - 运费），四舍五入到分，封顶 $remain */
    public static function share(string $price, int $qty, string $goodsAmount, string $payAmount, string $shippingFee, string $remain): string
    {
        if (bccomp($goodsAmount, '0', 2) <= 0) {
            return '0.00';
        }
        $goodsPaid = bcsub($payAmount, $shippingFee, 2);
        if (bccomp($goodsPaid, '0', 2) <= 0) {
            return '0.00';
        }
        $share = Money::round(bcdiv(bcmul(Money::mul($price, $qty), $goodsPaid, 4), $goodsAmount, 6), 2);

        return bccomp($share, $remain, 2) > 0 ? bcadd($remain, '0', 2) : $share;
    }

    private function reduceSales(OrderItem $it, int $qty): void
    {
        if ($qty <= 0) {
            return;
        }
        DB::table('goods_sku')->where('id', $it->sku_id)->update(['sales' => DB::raw("GREATEST(sales - {$qty}, 0)")]);
        DB::table('goods')->where('id', $it->goods_id)->update(['sales' => DB::raw("GREATEST(sales - {$qty}, 0)")]);
    }

    /** 加锁取售后单和订单（先锁订单再锁售后单，与申请时的加锁顺序一致）。传 $memberId 时校验归属 */
    private function lock(int $refundId, ?int $memberId = null): array
    {
        $orderId = OrderRefund::query()->whereKey($refundId)->value('order_id') ?? throw new BusinessException('售后单不存在');
        $order = Order::query()->lockForUpdate()->find($orderId) ?? throw new BusinessException('订单不存在');
        $refund = OrderRefund::query()->lockForUpdate()->find($refundId) ?? throw new BusinessException('售后单不存在');
        if ($memberId !== null && (int) $refund->member_id !== $memberId) {
            throw new BusinessException('售后单不存在');
        }

        return [$refund, $order];
    }

    /** 售后单状态流转（带前置状态校验） */
    private function move(OrderRefund $refund, array $from, int $to, array $extra = []): void
    {
        if (!in_array((int) $refund->status, $from, true)) {
            throw new BusinessException('售后单状态已变化（当前：' . (OrderRefund::STATUS_TEXT[(int) $refund->status] ?? '未知') . '），请刷新后重试');
        }
        $refund->update(['status' => $to] + $extra);
    }

    private function makeRefundNo(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $no = 'RF' . date('ymdHis') . random_int(100000, 999999);
            if (!OrderRefund::query()->where('refund_no', $no)->exists()) {
                return $no;
            }
        }
        throw new BusinessException('系统繁忙，请重试');
    }
}
