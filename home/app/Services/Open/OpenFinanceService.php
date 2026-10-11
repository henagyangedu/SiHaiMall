<?php

namespace App\Services\Open;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PayTrade;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Collection;

/** 开放接口：给财务系统对账用的支付流水和售后单，只读 */
class OpenFinanceService
{
    /**
     * 支付流水（收款和退款各是一条），按更新时间从早到晚。
     * @param array{updated_after?:string,updated_before?:string,trade_type?:int|string,status?:int|string,page?:int|string,page_size?:int|string} $q
     */
    public function payTrades(array $q): array
    {
        $size = min(100, max(1, (int) ($q['page_size'] ?? 50)));
        $p = PayTrade::query()
            ->when(($q['updated_after'] ?? '') !== '', fn ($x) => $x->where('updated_at', '>=', OpenTime::parse($q['updated_after'])))
            ->when(($q['updated_before'] ?? '') !== '', fn ($x) => $x->where('updated_at', '<', OpenTime::parse($q['updated_before'])))
            ->when(($q['trade_type'] ?? '') !== '', fn ($x) => $x->where('trade_type', (int) $q['trade_type']))
            ->when(($q['status'] ?? '') !== '', fn ($x) => $x->where('status', (int) $q['status']))
            ->orderBy('updated_at')->orderBy('id')
            ->paginate($size, ['*'], 'page', max(1, (int) ($q['page'] ?? 1)));

        $rows = collect($p->items());
        $orderNos = $this->orderNos($rows->pluck('order_id'));
        $list = $rows->map(fn (PayTrade $t) => [
            'trade_no' => (string) $t->trade_no, 'order_no' => (string) ($orderNos[$t->order_id] ?? ''),
            // 1 收款，2 退款
            'trade_type' => (int) $t->trade_type, 'channel' => (string) $t->channel, 'channel_trade_no' => $t->channelNo(),
            'currency' => (string) $t->currency, 'amount' => (string) $t->amount, 'fee_amount' => (string) $t->fee_amount,
            'status' => (int) $t->status, 'status_text' => PayTrade::STATUS_TEXT[(int) $t->status] ?? '',
            'paid_at' => OpenTime::format($t->paid_at), 'created_at' => OpenTime::format($t->created_at), 'updated_at' => OpenTime::format($t->updated_at),
        ])->all();

        return ['list' => $list, 'total' => $p->total(), 'page' => $p->currentPage(), 'page_size' => $p->perPage()];
    }

    /**
     * 售后单，按更新时间从早到晚。
     * @param array{updated_after?:string,updated_before?:string,status?:int|string,order_no?:string,page?:int|string,page_size?:int|string} $q
     */
    public function refunds(array $q): array
    {
        $size = min(100, max(1, (int) ($q['page_size'] ?? 50)));
        $orderId = null;
        if (($q['order_no'] ?? '') !== '') {
            $orderId = (int) Order::query()->withTrashed()->where('order_no', $q['order_no'])->value('id');
        }
        $p = OrderRefund::query()
            ->when(($q['updated_after'] ?? '') !== '', fn ($x) => $x->where('updated_at', '>=', OpenTime::parse($q['updated_after'])))
            ->when(($q['updated_before'] ?? '') !== '', fn ($x) => $x->where('updated_at', '<', OpenTime::parse($q['updated_before'])))
            ->when(($q['status'] ?? '') !== '', fn ($x) => $x->where('status', (int) $q['status']))
            ->when($orderId !== null, fn ($x) => $x->where('order_id', $orderId))
            ->orderBy('updated_at')->orderBy('id')
            ->paginate($size, ['*'], 'page', max(1, (int) ($q['page'] ?? 1)));

        return ['list' => $this->formatRefunds(collect($p->items())), 'total' => $p->total(), 'page' => $p->currentPage(), 'page_size' => $p->perPage()];
    }

    public function refund(string $refundNo): array
    {
        $r = OrderRefund::query()->where('refund_no', $refundNo)->first() ?? throw new BusinessException('售后单不存在', 404);

        return $this->formatRefunds(collect([$r]))[0];
    }

    /** @param Collection<int,OrderRefund> $rows */
    private function formatRefunds(Collection $rows): array
    {
        $orderNos = $this->orderNos($rows->pluck('order_id'));

        return $rows->map(fn (OrderRefund $r) => [
            'refund_no' => (string) $r->refund_no, 'order_no' => (string) ($orderNos[$r->order_id] ?? ''),
            // 0 = 整单退款（含运费）；否则是订单详情里商品行的 id
            'order_item_id' => (int) $r->order_item_id,
            'type' => (int) $r->type, 'type_text' => OrderRefund::TYPE_TEXT[(int) $r->type] ?? '',
            'status' => (int) $r->status, 'status_text' => OrderRefund::STATUS_TEXT[(int) $r->status] ?? '',
            'qty' => (int) $r->qty, 'currency' => (string) $r->currency, 'refund_amount' => (string) $r->refund_amount,
            'reason' => (string) $r->reason, 'reject_reason' => (string) $r->reject_reason,
            'return_express' => (string) $r->return_express, 'return_express_no' => (string) $r->return_express_no,
            // 渠道的退款单号；线下退款时为空
            'refund_trade_no' => (string) $r->refund_trade_no,
            'created_at' => OpenTime::format($r->created_at), 'finished_at' => OpenTime::format($r->finished_at), 'updated_at' => OpenTime::format($r->updated_at),
        ])->values()->all();
    }

    /** @return Collection<int,string> 订单 ID => 订单号 */
    private function orderNos(Collection $ids): Collection
    {
        return Order::query()->withTrashed()->whereIn('id', $ids->unique()->all())->pluck('order_no', 'id');
    }
}
