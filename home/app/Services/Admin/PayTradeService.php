<?php

namespace App\Services\Admin;

use App\Models\PayTrade;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

class PayTradeService
{
    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 20), 1), 100);
        $no = trim((string) ($f['keyword'] ?? ''));

        $p = DB::table('pay_trade as t')
            ->leftJoin('order as o', 'o.id', '=', 't.order_id')
            ->when($no !== '', function ($q) use ($no) {
                $like = addcslashes($no, '%_\\') . '%';
                $q->where(fn ($w) => $w->where('t.trade_no', 'like', $like)->orWhere('o.order_no', 'like', $like)->orWhere('t.channel_trade_no', 'like', $like));
            })
            ->when(!empty($f['channel']), fn ($q) => $q->where('t.channel', (string) $f['channel']))
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('t.status', (int) $f['status']))
            ->when(!empty($f['trade_type']), fn ($q) => $q->where('t.trade_type', (int) $f['trade_type']))
            ->when(!empty($f['flagged']), fn ($q) => $q->where('t.raw_response', 'like', '%"flag":"_%'))
            ->when(!empty($f['date_from']), fn ($q) => $q->where('t.created_at', '>=', $f['date_from'] . ' 00:00:00'))
            ->when(!empty($f['date_to']), fn ($q) => $q->where('t.created_at', '<=', $f['date_to'] . ' 23:59:59'))
            ->orderByDesc('t.id')
            ->select('t.*', 'o.order_no')
            ->paginate($size);

        $p->through(function ($r) {
            $raw = json_decode((string) $r->raw_response, true) ?: [];

            return [
                'id' => (int) $r->id, 'trade_no' => $r->trade_no, 'order_id' => (int) $r->order_id, 'order_no' => (string) ($r->order_no ?? ''),
                'channel' => $r->channel, 'channel_trade_no' => $r->channel_trade_no === $r->trade_no ? '' : $r->channel_trade_no,
                'trade_type' => (int) $r->trade_type, 'trade_type_text' => (int) $r->trade_type === 2 ? '退款' : '支付',
                'currency' => $r->currency, 'amount' => (string) $r->amount, 'fee_amount' => (string) $r->fee_amount,
                'status' => (int) $r->status, 'status_text' => PayTrade::STATUS_TEXT[(int) $r->status] ?? '',
                'flag' => (string) ($raw['flag'] ?? ''), 'error' => (string) ($raw['error'] ?? ''),
                'paid_at' => (string) $r->paid_at, 'created_at' => (string) $r->created_at,
            ];
        });

        return Paginate::format($p);
    }
}
