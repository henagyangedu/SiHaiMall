<?php

namespace App\Services\Admin;

use App\Models\PayNotifyLog;
use App\Services\Common\PayService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;

class PayNotifyService
{
    public function __construct(private PayService $pay) {}

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 20), 1), 100);

        $p = PayNotifyLog::query()
            ->when(!empty($f['channel']), fn ($q) => $q->where('channel', (string) $f['channel']))
            ->when(isset($f['handle_status']) && $f['handle_status'] !== '', fn ($q) => $q->where('handle_status', (int) $f['handle_status']))
            ->when(!empty($f['trade_no']), fn ($q) => $q->where('trade_no', 'like', addcslashes((string) $f['trade_no'], '%_\\') . '%'))
            ->when(!empty($f['date_from']), fn ($q) => $q->where('created_at', '>=', $f['date_from'] . ' 00:00:00'))
            ->when(!empty($f['date_to']), fn ($q) => $q->where('created_at', '<=', $f['date_to'] . ' 23:59:59'))
            ->orderByDesc('id')
            ->select(['id', 'channel', 'event_id', 'trade_no', 'verify_ok', 'handle_status', 'error_msg', 'created_at'])
            ->paginate($size);

        $p->through(fn (PayNotifyLog $r) => [
            'id' => (int) $r->id, 'channel' => $r->channel, 'event_id' => $r->event_id, 'trade_no' => $r->trade_no,
            'verify_ok' => (int) $r->verify_ok, 'handle_status' => (int) $r->handle_status,
            'handle_text' => PayNotifyLog::STATUS_TEXT[(int) $r->handle_status] ?? '', 'error_msg' => $r->error_msg,
            'created_at' => (string) $r->created_at,
        ]);

        return Paginate::format($p);
    }

    /** 查看原文（含渠道推送的买家信息，仅有「回调日志」权限的管理员可看；过长截断） */
    public function show(int $id): array
    {
        $r = PayNotifyLog::query()->find($id) ?? throw new BusinessException('记录不存在');
        $body = (string) $r->body;
        $pretty = json_encode(json_decode($body, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'id' => (int) $r->id, 'channel' => $r->channel, 'event_id' => $r->event_id, 'trade_no' => $r->trade_no,
            'headers' => json_decode((string) $r->headers, true) ?: [], 'body' => mb_substr($pretty && $pretty !== 'null' ? $pretty : $body, 0, 12000),
        ];
    }

    public function retry(int $id): void
    {
        $this->pay->reprocess($id);
    }
}
