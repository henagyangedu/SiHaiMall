<?php

namespace App\Services\Admin;

use App\Models\MemberPointLog;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

class MemberPointService
{
    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 20), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));

        $p = DB::table('member_point_log as l')
            ->leftJoin('member as m', 'm.id', '=', 'l.member_id')
            ->leftJoin('admin as a', 'a.id', '=', 'l.admin_id')
            ->when(!empty($f['member_id']), fn ($q) => $q->where('l.member_id', (int) $f['member_id']))
            ->when($kw !== '', function ($q) use ($kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->where(function ($w) use ($like, $kw) {
                    $w->where('m.nickname', 'like', $like)->orWhere('m.phone', 'like', $like)->orWhere('m.email', 'like', $like)->orWhere('m.account', 'like', $like);
                    if (ctype_digit($kw)) {
                        $w->orWhere('l.member_id', (int) $kw);
                    }
                });
            })
            ->when(!empty($f['type']), fn ($q) => $q->where('l.type', (int) $f['type']))
            ->when(!empty($f['date_from']), fn ($q) => $q->where('l.created_at', '>=', $f['date_from'] . ' 00:00:00'))
            ->when(!empty($f['date_to']), fn ($q) => $q->where('l.created_at', '<=', $f['date_to'] . ' 23:59:59'))
            ->orderByDesc('l.id')
            ->select('l.*', 'm.nickname', 'm.account', 'a.username as admin_name')
            ->paginate($size);

        $p->through(fn ($r) => [
            'id' => (int) $r->id, 'member_id' => (int) $r->member_id,
            'member' => ($r->nickname ?: $r->account) ?: ('#' . $r->member_id),
            'type' => (int) $r->type, 'type_text' => MemberPointLog::TYPE_TEXT[(int) $r->type] ?? '其它',
            'change_points' => (int) $r->change_points, 'after_points' => (int) $r->after_points,
            'ref_no' => $r->ref_no, 'remark' => $r->remark,
            'operator' => (int) $r->admin_id > 0 ? (string) ($r->admin_name ?? '') : '系统',
            'created_at' => (string) $r->created_at,
        ]);

        return Paginate::format($p);
    }
}
