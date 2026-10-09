<?php

namespace App\Services\Api;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Common\OrderRefundService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/** C 端售后：可退信息、申请、列表、详情、撤销、填写退货物流。流转规则见 Common\OrderRefundService */
class RefundService
{
    public function __construct(private OrderRefundService $refunds) {}

    public function refundable(int $memberId, string $orderNo): array
    {
        return $this->refunds->refundable($this->order($memberId, $orderNo));
    }

    public function apply(int $memberId, string $orderNo, array $d): array
    {
        $refund = $this->refunds->apply((int) $this->order($memberId, $orderNo)->id, $memberId, $d);

        return ['refund_no' => $refund->refund_no, 'refund_amount' => (string) $refund->refund_amount, 'currency' => $refund->currency];
    }

    public function list(int $memberId, int $page, int $size): array
    {
        $p = OrderRefund::query()->where('member_id', $memberId)->orderByDesc('id')
            ->paginate(min(max($size, 1), 50), ['*'], 'page', max($page, 1));

        $orders = Order::query()->withTrashed()->whereIn('id', collect($p->items())->pluck('order_id'))->pluck('order_no', 'id');
        $items = DB::table('order_item')->whereIn('order_id', collect($p->items())->pluck('order_id'))->get()->groupBy('order_id');
        $p->setCollection(collect($p->items())->map(fn (OrderRefund $r) => $this->brief($r, (string) ($orders[$r->order_id] ?? ''), $items->get($r->order_id, collect()))));

        return Paginate::format($p);
    }

    public function detail(int $memberId, string $refundNo): array
    {
        $r = $this->own($memberId, $refundNo);
        $orderNo = (string) Order::query()->withTrashed()->whereKey($r->order_id)->value('order_no');
        $items = DB::table('order_item')->where('order_id', $r->order_id)->get();

        return $this->brief($r, $orderNo, $items) + [
            'reason' => $r->reason, 'images' => json_decode((string) ($r->images ?: '[]'), true) ?: [],
            'reject_reason' => $r->reject_reason, 'return_express' => $r->return_express, 'return_express_no' => $r->return_express_no,
            'finished_at' => $r->finished_at?->format('Y-m-d H:i:s'),
            'can_cancel' => in_array((int) $r->status, [OrderRefund::PENDING, OrderRefund::WAIT_RETURN], true),
            'can_submit_return' => (int) $r->status === OrderRefund::WAIT_RETURN,
        ];
    }

    public function cancel(int $memberId, string $refundNo): void
    {
        $this->refunds->cancel((int) $this->own($memberId, $refundNo)->id, $memberId);
    }

    public function submitReturn(int $memberId, string $refundNo, string $express, string $expressNo): void
    {
        $this->refunds->submitReturn((int) $this->own($memberId, $refundNo)->id, $memberId, $express, $expressNo);
    }

    private function order(int $memberId, string $orderNo): Order
    {
        return Order::query()->where('member_id', $memberId)->where('order_no', $orderNo)->first() ?? throw new BusinessException('订单不存在');
    }

    private function own(int $memberId, string $refundNo): OrderRefund
    {
        return OrderRefund::query()->where('member_id', $memberId)->where('refund_no', $refundNo)->first() ?? throw new BusinessException('售后单不存在');
    }

    /** @param \Illuminate\Support\Collection<int,object> $orderItems 该订单的全部商品行 */
    private function brief(OrderRefund $r, string $orderNo, $orderItems): array
    {
        $rows = (int) $r->order_item_id === 0 ? $orderItems : $orderItems->where('id', (int) $r->order_item_id);

        return [
            'refund_no' => $r->refund_no, 'order_no' => $orderNo, 'whole_order' => (int) $r->order_item_id === 0,
            'type' => (int) $r->type, 'type_text' => OrderRefund::TYPE_TEXT[(int) $r->type] ?? '',
            'status' => (int) $r->status, 'status_text' => OrderRefund::STATUS_TEXT[(int) $r->status] ?? '',
            'refund_amount' => (string) $r->refund_amount, 'currency' => $r->currency,
            'created_at' => $r->created_at?->format('Y-m-d H:i:s'),
            'items' => $rows->map(fn ($i) => [
                'item_id' => (int) $i->id, 'title' => $i->title, 'spec_text' => $i->spec_text, 'image' => $i->image,
                'price' => (string) $i->price, 'qty' => (int) $r->order_item_id === 0 ? (int) $i->qty : (int) $r->qty,
            ])->values()->all(),
        ];
    }
}
