<?php

namespace App\Services\Admin;

use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

class TranslateLogService
{
    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 20), 1), 100);

        $paginator = DB::table('translate_log as l')
            ->leftJoin('admin as a', 'a.id', '=', 'l.admin_id')
            ->when(!empty($f['driver']), fn ($q) => $q->where('l.driver', $f['driver']))
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('l.status', (int) $f['status']))
            ->when(!empty($f['date_from']), fn ($q) => $q->where('l.created_at', '>=', $f['date_from'] . ' 00:00:00'))
            ->when(!empty($f['date_to']), fn ($q) => $q->where('l.created_at', '<=', $f['date_to'] . ' 23:59:59'))
            ->orderByDesc('l.id')
            ->select('l.*', 'a.username')
            ->paginate($size)
            ->through(fn ($r) => [
                'id' => (int) $r->id, 'admin' => (string) ($r->username ?? ''), 'driver' => $r->driver, 'model' => $r->model,
                'from_lang' => $r->from_lang, 'to_lang' => $r->to_lang,
                'input_chars' => (int) $r->input_chars, 'output_chars' => (int) $r->output_chars,
                'cached' => (int) $r->cached, 'status' => (int) $r->status, 'error' => $r->error, 'created_at' => $r->created_at,
            ]);

        $today = (int) DB::table('translate_log')->where('status', 1)->where('cached', 0)->where('created_at', '>=', today())->sum('input_chars');

        return Paginate::format($paginator) + ['today_chars' => $today];
    }
}
