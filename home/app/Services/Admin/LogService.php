<?php

namespace App\Services\Admin;

use App\Models\AdminLog;
use App\Support\Paginate;

class LogService
{
    public function record(array $data): void
    {
        AdminLog::query()->create($data);
    }

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 20), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));

        $paginator = AdminLog::query()
            ->with('admin:id,username,nickname')
            ->when(!empty($f['admin_id']), fn ($q) => $q->where('admin_id', (int) $f['admin_id']))
            ->when(!empty($f['module']), fn ($q) => $q->where('module', $f['module']))
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('status', (int) $f['status']))
            ->when($kw !== '', function ($q) use ($kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->where(fn ($w) => $w->where('action', 'like', $like)->orWhere('url', 'like', $like));
            })
            ->when(!empty($f['date_from']), fn ($q) => $q->where('created_at', '>=', $f['date_from'] . ' 00:00:00'))
            ->when(!empty($f['date_to']), fn ($q) => $q->where('created_at', '<=', $f['date_to'] . ' 23:59:59'))
            ->orderByDesc('id')
            ->paginate($size);

        return Paginate::format($paginator);
    }
}
