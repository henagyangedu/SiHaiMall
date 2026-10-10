<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PayTrade;
use App\Services\Common\OrderRefundService;
use App\Support\Exceptions\BusinessException;
use App\Support\OrderStatus;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/** 后台售后订单：列表、详情，以及审核 / 确认收货 / 退款操作的入口。流转规则见 Common\OrderRefundService */
class RefundService
{
    public function __construct(private OrderRefundService $refunds) {}

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));

        $paginator = OrderRefund::query()
            ->when($kw !== '', function ($q) use ($kw) {
                $like = addcslashes($kw, '%_\\') . '%';
                $q->where(fn ($w) => $w->where('refund_no', 'like', $like)
                    ->orWhereIn('order_id', DB::table('order')->where('order_no', 'like', $like)->select('id')));
            })
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('status', (int) $f['status']))
            ->when(!empty($f['type']), fn ($q) => $q->where('type', (int) $f['type']))
            ->orderByDesc('id')->paginate($size);

        $rows = collect($paginator->items());
        $orders = Order::query()->withTrashed()->whereIn('id', $rows->pluck('order_id'))->get(['id', 'order_no', 'pay_channel'])->keyBy('id');
        $members = DB::table('member')->whereIn('id', $rows->pluck('member_id'))->get(['id', 'nickname', 'account'])->keyBy('id');
        $items = DB::table('order_item')->whereIn('id', $rows->pluck('order_item_id')->filter())->get(['id', 'title', 'spec_text', 'image'])->keyBy('id');

        $paginator->through(function (OrderRefund $r) use ($orders, $members, $items) {
            $m = $members->get($r->member_id);
            $it = $items->get($r->order_item_id);

            return $this->base($r) + [
                'order_no' => (string) ($orders->get($r->order_id)->order_no ?? ''),
                'member' => $m ? ($m->nickname ?: $m->account) : '',
                'goods' => $it ? trim($it->title . ' ' . $it->spec_text) : '整单',
                'image' => $it->image ?? '',
            ];
        });

        return Paginate::format($paginator);
    }

    /** 各状态数量（页签角标） */
    public function counts(): array
    {
        $rows = OrderRefund::query()->groupBy('status')->selectRaw('status, count(*) as c')->pluck('c', 'status');

        return collect(OrderRefund::STATUS_TEXT)->map(fn ($t, $k) => ['status' => $k, 'text' => $t, 'count' => (int) ($rows[$k] ?? 0)])->values()->all();
    }

    public function detail(int $id): array
    {
        $r = OrderRefund::query()->find($id) ?? throw new BusinessException('售后单不存在');
        $o = Order::query()->withTrashed()->find($r->order_id) ?? throw new BusinessException('订单不存在');
        $member = DB::table('member')->where('id', $r->member_id)->first(['id', 'nickname', 'account']);
        $itemRows = DB::table('order_item')->where('order_id', $o->id)
            ->when((int) $r->order_item_id > 0, fn ($q) => $q->where('id', $r->order_item_id))->orderBy('id')->get();
        $paid = PayTrade::query()->where('order_id', $o->id)->where('trade_type', 1)->where('status', PayTrade::SUCCESS)->orderByDesc('id')->first();
        $refunded = (string) OrderRefund::query()->where('order_id', $o->id)->where('status', OrderRefund::DONE)->sum('refund_amount');

        return $this->base($r) + [
            'reason' => $r->reason, 'images' => json_decode((string) ($r->images ?: '[]'), true) ?: [],
            'reject_reason' => $r->reject_reason, 'return_express' => $r->return_express, 'return_express_no' => $r->return_express_no,
            'refund_trade_no' => $r->refund_trade_no, 'finished_at' => $r->finished_at?->format('Y-m-d H:i:s'),
            'member' => $member ? ['id' => (int) $member->id, 'name' => $member->nickname ?: $member->account] : null,
            'order' => [
                'id' => (int) $o->id, 'order_no' => $o->order_no, 'status_text' => OrderStatus::TEXT[(int) $o->status] ?? '',
                'pay_status_text' => OrderStatus::PAY_TEXT[(int) $o->pay_status] ?? '', 'pay_channel' => $o->pay_channel,
                'goods_amount' => (string) $o->goods_amount, 'shipping_fee' => (string) $o->shipping_fee, 'tax_amount' => (string) $o->tax_amount, 'tax_name' => (string) $o->tax_name, 'tax_included' => (int) $o->tax_included, 'pay_amount' => (string) $o->pay_amount,
                'refunded_amount' => bcadd($refunded, '0', 2),
                // 没有在线支付流水（线下收款）的订单只能登记线下退款
                'online_paid' => $paid !== null, 'paid_at' => $o->paid_at?->format('Y-m-d H:i:s'),
            ],
            'items' => $itemRows->map(fn ($i) => [
                'id' => (int) $i->id, 'title' => $i->title, 'spec_text' => $i->spec_text, 'image' => $i->image, 'price' => (string) $i->price,
                'qty' => (int) $i->qty, 'refund_qty' => (int) $i->refund_qty, 'ship_status' => (int) $i->ship_status,
                'apply_qty' => (int) $r->order_item_id === 0 ? (int) $i->qty : (int) $r->qty,
            ])->all(),
        ];
    }

    /** 商家代买家发起售后时，订单当前可退的商品、数量和金额（不受售后申请期限限制） */
    public function refundable(int $orderId): array
    {
        $order = Order::query()->find($orderId) ?? throw new BusinessException('订单不存在');

        return ['order_no' => $order->order_no, 'currency' => $order->currency] + $this->refunds->refundable($order, true);
    }

    /**
     * 商家代买家发起售后（直接视为审核通过）。
     * @return array{id:int,refund_no:string,status:int}
     */
    public function apply(int $orderId, array $d, Admin $op): array
    {
        $refund = $this->refunds->applyByAdmin($orderId, (int) $op->id, $d);

        return ['id' => (int) $refund->id, 'refund_no' => $refund->refund_no, 'status' => (int) $refund->status];
    }

    public function audit(int $id, bool $pass, ?string $amount, string $rejectReason, Admin $op): void
    {
        if ($pass) {
            $this->refunds->approve($id, (int) $op->id, $amount);

            return;
        }
        if (trim($rejectReason) === '') {
            throw new BusinessException('请填写拒绝原因');
        }
        $this->refunds->reject($id, (int) $op->id, $rejectReason);
    }

    public function confirmReturn(int $id, Admin $op): void
    {
        $this->refunds->confirmReturn($id, (int) $op->id);
    }

    /** @return array{done:bool,msg:string} */
    public function pay(int $id, bool $offline, Admin $op): array
    {
        return $this->refunds->pay($id, (int) $op->id, $offline);
    }

    private function base(OrderRefund $r): array
    {
        return [
            'id' => (int) $r->id, 'refund_no' => $r->refund_no, 'whole_order' => (int) $r->order_item_id === 0,
            'type' => (int) $r->type, 'type_text' => OrderRefund::TYPE_TEXT[(int) $r->type] ?? '',
            'status' => (int) $r->status, 'status_text' => OrderRefund::STATUS_TEXT[(int) $r->status] ?? '',
            'qty' => (int) $r->qty, 'refund_amount' => (string) $r->refund_amount, 'currency' => $r->currency,
            'created_at' => $r->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
