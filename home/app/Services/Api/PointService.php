<?php

namespace App\Services\Api;

use App\Models\MemberPointLog;
use App\Support\Paginate;

/** C 端积分明细（只读）。积分的增减规则见 Common\OrderPointsService 和后台的会员积分调整 */
class PointService
{
    /**
     * 会员自己的积分流水，新的在前。
     * 不返回 remark：那是给后台看的中文说明（可能含内部备注），C 端按 type 显示对应语言的文字。
     */
    public function logs(int $memberId, int $page, int $size): array
    {
        $p = MemberPointLog::query()->where('member_id', $memberId)->orderByDesc('id')
            ->paginate(min(max($size, 1), 50), ['id', 'type', 'change_points', 'after_points', 'ref_no', 'created_at'], 'page', max($page, 1));

        $p->setCollection(collect($p->items())->map(fn (MemberPointLog $r) => [
            'id' => (int) $r->id, 'type' => (int) $r->type, 'change_points' => (int) $r->change_points, 'after_points' => (int) $r->after_points,
            'ref_no' => (string) $r->ref_no, 'created_at' => $r->created_at ? date('Y-m-d H:i:s', strtotime((string) $r->created_at)) : null,
        ]));

        return Paginate::format($p);
    }
}
