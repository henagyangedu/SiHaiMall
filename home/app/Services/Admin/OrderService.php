<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\Order;
use App\Services\Common\OrderCloseService;
use App\Services\Common\OrderStatusService;
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use App\Support\OrderStatus;
use App\Support\Paginate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(private OrderStatusService $status, private OrderCloseService $closer) {}

    /** @param int[]|null $onlyStatus 限定状态范围（发货管理页只看待发货/待收货） */
    public function list(array $f, ?array $onlyStatus = null): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);

        $paginator = $this->filtered($f, $onlyStatus)->orderByDesc('id')->paginate($size);
        $ids = collect($paginator->items())->pluck('id')->all();

        $members = DB::table('member')->whereIn('id', collect($paginator->items())->pluck('member_id')->unique())->get(['id', 'nickname', 'account'])->keyBy('id');
        $addr = DB::table('order_address')->whereIn('order_id', $ids)->get()->keyBy('order_id');
        $items = DB::table('order_item')->whereIn('order_id', $ids)->orderBy('id')->get()->groupBy('order_id');

        $paginator->through(function (Order $o) use ($members, $addr, $items) {
            $its = $items->get($o->id, collect());
            $first = $its->first();
            $m = $members->get($o->member_id);
            $a = $addr->get($o->id);

            return [
                'id' => (int) $o->id, 'order_no' => $o->order_no,
                'member_id' => (int) $o->member_id, 'member' => $m ? ($m->nickname ?: $m->account ?: '#' . $m->id) : '#' . $o->member_id,
                'receiver' => $a ? $a->name . ' ' . $a->phone : '',
                'first_title' => $first->title ?? '', 'first_image' => $first->image ?? '',
                'item_count' => $its->count(), 'qty' => (int) $its->sum('qty'),
                'currency' => $o->currency, 'pay_amount' => (string) $o->pay_amount,
                'status' => (int) $o->status, 'status_text' => OrderStatus::TEXT[(int) $o->status] ?? '',
                'pay_status' => (int) $o->pay_status, 'pay_status_text' => OrderStatus::PAY_TEXT[(int) $o->pay_status] ?? '',
                'pay_channel' => $o->pay_channel, 'source' => $o->source,
                'unshipped' => (int) $its->where('ship_status', 0)->count(),
                'admin_remark' => $o->admin_remark,
                'created_at' => $o->created_at?->format('Y-m-d H:i:s'), 'paid_at' => $o->paid_at?->format('Y-m-d H:i:s'),
            ];
        });

        return Paginate::format($paginator);
    }

    public const EXPORT_HEAD = [
        '订单号', '下单时间', '订单状态', '支付状态', '支付方式', '会员', '币种', '商品金额', '运费', '套餐优惠', '优惠', '优惠券', '积分抵扣', '应付', '折合基准币种',
        '定价区域', '商品明细', '收件人', '电话', '国家', '省', '市', '区', '详细地址', '买家留言', '商家备注', '支付时间', '发货时间', '完成时间',
    ];

    private const MAX_EXPORT = 50000;

    /** 导出：按列表页同样的筛选条件分批读取，最多 5 万行；返回生成器供控制器流式输出 */
    public function exportRows(array $f): \Generator
    {
        $n = 0;
        // 每 500 单一批取会员、地址、商品明细，避免逐单查询
        foreach ($this->filtered($f, null)->lazyByIdDesc(500)->chunk(500) as $chunk) {
            foreach ($this->exportBatch($chunk->values()->all()) as $row) {
                if (++$n > self::MAX_EXPORT) {
                    return;
                }
                yield $row;
            }
        }
    }

    /** @param Order[] $orders */
    private function exportBatch(array $orders): array
    {
        if ($orders === []) {
            return [];
        }
        $ids = array_map(fn (Order $o) => (int) $o->id, $orders);
        $members = DB::table('member')->whereIn('id', array_unique(array_map(fn (Order $o) => (int) $o->member_id, $orders)))->get(['id', 'nickname', 'account'])->keyBy('id');
        $addr = DB::table('order_address')->whereIn('order_id', $ids)->get()->keyBy('order_id');
        $items = DB::table('order_item')->whereIn('order_id', $ids)->orderBy('id')->get()->groupBy('order_id');

        $fmt = fn ($t) => $t?->format('Y-m-d H:i:s') ?? '';
        $rows = [];
        foreach ($orders as $o) {
            $m = $members->get($o->member_id);
            $a = $addr->get($o->id);
            $goods = $items->get($o->id, collect())->map(fn ($i) => trim($i->title . ' ' . $i->spec_text) . ' ×' . (int) $i->qty)->implode('；');
            $rows[] = [
                $o->order_no, $fmt($o->created_at), OrderStatus::TEXT[(int) $o->status] ?? '', OrderStatus::PAY_TEXT[(int) $o->pay_status] ?? '', $o->pay_channel,
                $m ? ($m->nickname ?: $m->account ?: '#' . $m->id) : '#' . $o->member_id, $o->currency,
                (string) $o->goods_amount, (string) $o->shipping_fee, (string) $o->bundle_amount, (string) $o->discount_amount, (string) $o->coupon_amount, (string) $o->points_amount, (string) $o->pay_amount, (string) $o->base_pay_amount,
                (string) $o->price_region_name, $goods,
                $a->name ?? '', $a ? trim($a->phone_area . ' ' . $a->phone) : '', $a->country_code ?? '', $a->province ?? '', $a->city ?? '', $a->district ?? '',
                $a ? trim($a->address . ' ' . $a->address2) : '', $o->remark, $o->admin_remark, $fmt($o->paid_at), $fmt($o->shipped_at), $fmt($o->finished_at),
            ];
        }

        return $rows;
    }

    /** 各状态订单数（列表页页签角标） */
    public function counts(): array
    {
        $rows = Order::query()->groupBy('status')->selectRaw('status, count(*) as c')->pluck('c', 'status');

        return collect(OrderStatus::TEXT)->map(fn ($t, $k) => ['status' => $k, 'text' => $t, 'count' => (int) ($rows[$k] ?? 0)])->values()->all();
    }

    public function detail(int $id): array
    {
        $o = $this->find($id);
        $member = DB::table('member')->where('id', $o->member_id)->first(['id', 'nickname', 'account', 'phone', 'phone_area', 'email']);

        $logs = DB::table('order_status_log as l')
            ->leftJoin('admin as a', fn ($j) => $j->on('a.id', '=', 'l.operator_id')->where('l.operator_type', OrderStatus::OP_ADMIN))
            ->where('l.order_id', $id)->orderBy('l.id')->select('l.*', 'a.username')->get()
            ->map(fn ($r) => [
                'from' => OrderStatus::TEXT[(int) $r->from_status] ?? '', 'to' => OrderStatus::TEXT[(int) $r->to_status] ?? '',
                'operator' => match ((int) $r->operator_type) { 2 => (string) ($r->username ?? '管理员'), 1 => '会员', default => '系统' },
                'remark' => $r->remark, 'created_at' => (string) $r->created_at,
            ])->all();

        $items = DB::table('order_item')->where('order_id', $id)->orderBy('id')->get()->map(fn ($i) => [
            'id' => (int) $i->id, 'goods_id' => (int) $i->goods_id, 'sku_id' => (int) $i->sku_id, 'title' => $i->title, 'spec_text' => $i->spec_text,
            'image' => $i->image, 'price' => (string) $i->price, 'qty' => (int) $i->qty, 'subtotal' => Money::mul((string) $i->price, (int) $i->qty),
            'refund_qty' => (int) $i->refund_qty, 'ship_status' => (int) $i->ship_status,
        ])->all();

        $ship = DB::table('shipping')->where('order_id', $id)->orderBy('id')->get()->map(fn ($s) => [
            'id' => (int) $s->id, 'express_code' => $s->express_code, 'express_name' => $s->express_name, 'express_no' => $s->express_no, 'label_url' => (string) $s->label_url,
            'item_ids' => array_values(array_filter(array_map('intval', explode(',', (string) $s->item_ids)))),
            'shipped_at' => (string) $s->shipped_at,
        ])->all();

        $trades = DB::table('pay_trade')->where('order_id', $id)->orderBy('id')->get()->map(fn ($t) => [
            'trade_no' => $t->trade_no, 'channel' => $t->channel, 'channel_trade_no' => $t->channel_trade_no === $t->trade_no ? '' : $t->channel_trade_no,
            'trade_type' => (int) $t->trade_type === 2 ? '退款' : '支付', 'currency' => $t->currency, 'amount' => (string) $t->amount,
            'status' => ['待支付', '成功', '失败', '已关闭'][(int) $t->status] ?? '', 'paid_at' => (string) $t->paid_at,
        ])->all();

        return [
            'id' => (int) $o->id, 'order_no' => $o->order_no, 'status' => (int) $o->status, 'status_text' => OrderStatus::TEXT[(int) $o->status] ?? '',
            'pay_status' => (int) $o->pay_status, 'pay_status_text' => OrderStatus::PAY_TEXT[(int) $o->pay_status] ?? '', 'pay_channel' => $o->pay_channel,
            'currency' => $o->currency, 'rate' => rtrim(rtrim((string) $o->rate, '0'), '.'), 'lang' => $o->lang, 'source' => $o->source,
            'price_region_name' => (string) $o->price_region_name,
            'goods_amount' => (string) $o->goods_amount, 'shipping_fee' => (string) $o->shipping_fee, 'discount_amount' => (string) $o->discount_amount,
            'coupon_amount' => (string) $o->coupon_amount, 'bundle_amount' => (string) $o->bundle_amount, 'bundles' => json_decode((string) ($o->bundle_info ?: '[]'), true) ?: [],
            'points_amount' => (string) $o->points_amount, 'pay_amount' => (string) $o->pay_amount, 'base_pay_amount' => (string) $o->base_pay_amount,
            'remark' => $o->remark, 'admin_remark' => $o->admin_remark, 'cancel_reason' => $o->cancel_reason,
            'expire_at' => $o->expire_at?->format('Y-m-d H:i:s'), 'paid_at' => $o->paid_at?->format('Y-m-d H:i:s'),
            'shipped_at' => $o->shipped_at?->format('Y-m-d H:i:s'), 'finished_at' => $o->finished_at?->format('Y-m-d H:i:s'),
            'created_at' => $o->created_at?->format('Y-m-d H:i:s'),
            'member' => $member ? ['id' => (int) $member->id, 'name' => $member->nickname ?: $member->account, 'phone' => $member->phone ? '+' . $member->phone_area . ' ' . $member->phone : '', 'email' => $member->email] : null,
            'address' => DB::table('order_address')->where('order_id', $id)->first(),
            'items' => $items, 'shippings' => $ship, 'trades' => $trades, 'logs' => $logs,
        ];
    }

    public function setRemark(int $id, string $remark, Admin $op): void
    {
        $o = $this->find($id);
        $o->update(['admin_remark' => $remark]);
        $this->status->note($o, OrderStatus::OP_ADMIN, (int) $op->id, '修改商家备注');
    }

    /** 改价：只允许待支付订单；通过调整 discount_amount 保持「商品+运费-套餐优惠-优惠-优惠券-积分=应付」恒等式 */
    public function changePrice(int $id, string $newPay, Admin $op): void
    {
        DB::transaction(function () use ($id, $newPay, $op) {
            $o = Order::query()->lockForUpdate()->find($id) ?? throw new BusinessException('订单不存在');
            if ((int) $o->status !== OrderStatus::PENDING) {
                throw new BusinessException('只有待支付订单可以改价');
            }
            $gross = bcsub(bcsub(bcsub(bcadd((string) $o->goods_amount, (string) $o->shipping_fee, 2), (string) $o->bundle_amount, 2), (string) $o->coupon_amount, 2), (string) $o->points_amount, 2);
            if (bccomp($newPay, '0.01', 2) < 0 || bccomp($newPay, $gross, 2) > 0) {
                throw new BusinessException('改后金额须在 0.01 ~ ' . $gross . ' 之间');
            }
            $old = (string) $o->pay_amount;
            if (bccomp($old, $newPay, 2) === 0) {
                throw new BusinessException('金额没有变化');
            }

            $o->update([
                'pay_amount' => $newPay, 'discount_amount' => bcsub($gross, $newPay, 2),
                'base_pay_amount' => Money::toBase($newPay, (string) $o->rate),
            ]);
            // 已生成的待支付流水金额已过期，作废后由支付模块在下次支付时按新金额重建
            DB::table('pay_trade')->where('order_id', $id)->where('trade_type', 1)->where('status', 0)->update(['status' => 3, 'updated_at' => now()]);
            $this->status->note($o, OrderStatus::OP_ADMIN, (int) $op->id, "改价：{$old} → {$newPay} {$o->currency}");
        });
    }

    public function cancel(int $id, string $reason, Admin $op): void
    {
        $o = $this->find($id);
        if ((int) $o->status !== OrderStatus::PENDING) {
            throw new BusinessException('只有待支付订单可以取消；已支付订单请走售后退款');
        }
        $this->closer->closeUnpaid($o, OrderStatus::CANCELLED, OrderStatus::OP_ADMIN, (int) $op->id, $reason !== '' ? $reason : '后台取消');
    }

    /** 后台确认收货：待收货 → 已完成 */
    public function receive(int $id, Admin $op): void
    {
        $o = $this->find($id);
        DB::transaction(fn () => $this->status->transit($o, [OrderStatus::SHIPPED], OrderStatus::FINISHED, OrderStatus::OP_ADMIN, (int) $op->id, '后台确认收货', ['finished_at' => now()]));
    }

    private function filtered(array $f, ?array $onlyStatus): Builder
    {
        $no = trim((string) ($f['order_no'] ?? ''));
        $kw = trim((string) ($f['keyword'] ?? ''));

        return Order::query()
            ->when($onlyStatus !== null, fn ($q) => $q->whereIn('status', $onlyStatus))
            ->when($no !== '', fn ($q) => $q->where('order_no', 'like', addcslashes($no, '%_\\') . '%'))
            ->when($kw !== '', function ($q) use ($kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->where(function ($w) use ($like, $kw) {
                    $w->whereIn('member_id', DB::table('member')->where(fn ($m) => $m->where('nickname', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like)->orWhere('account', 'like', $like))->select('id'))
                      ->orWhereIn('id', DB::table('order_address')->where(fn ($a) => $a->where('name', 'like', $like)->orWhere('phone', 'like', $like))->select('order_id'));
                    if (ctype_digit($kw)) {
                        $w->orWhere('member_id', (int) $kw);
                    }
                });
            })
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('status', (int) $f['status']))
            ->when(isset($f['pay_status']) && $f['pay_status'] !== '', fn ($q) => $q->where('pay_status', (int) $f['pay_status']))
            ->when(!empty($f['pay_channel']), fn ($q) => $q->where('pay_channel', (string) $f['pay_channel']))
            ->when(!empty($f['date_from']), fn ($q) => $q->where('created_at', '>=', $f['date_from'] . ' 00:00:00'))
            ->when(!empty($f['date_to']), fn ($q) => $q->where('created_at', '<=', $f['date_to'] . ' 23:59:59'));
    }

    private function find(int $id): Order
    {
        return Order::query()->find($id) ?? throw new BusinessException('订单不存在');
    }
}
