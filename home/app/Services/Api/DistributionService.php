<?php

namespace App\Services\Api;

use App\Models\DistributionCommission;
use App\Models\DistributionWithdraw;
use App\Models\Distributor;
use App\Models\Member;
use App\Services\Common\DistributionService as Core;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/** C 端分销中心：概况、申请、佣金明细、我的团队、提现。规则见 Common\DistributionService */
class DistributionService
{
    public function __construct(private Core $core) {}

    public function summary(Member $m): array
    {
        $s = $this->core->settings();
        $d = Distributor::query()->find($m->id);
        $level1 = Member::query()->where('parent_id', $m->id)->pluck('id');

        return [
            'enabled' => $s['enabled'], 'level' => $s['level'], 'rate_1' => $s['rate_1'], 'rate_2' => $s['level'] === 2 ? $s['rate_2'] : '0.00',
            'min_withdraw' => $s['min_withdraw'],
            'invite_code' => Core::inviteCode((int) $m->id),
            // null = 还没申请；0待审核 1正常 2已拒绝 3已禁用
            'status' => $d ? (int) $d->status : null, 'status_text' => $d ? Distributor::STATUS_TEXT[(int) $d->status] : '未申请',
            'reject_reason' => $d->reject_reason ?? '',
            'pending_amount' => (string) ($d->pending_amount ?? '0.00'), 'available_amount' => (string) ($d->available_amount ?? '0.00'),
            'frozen_amount' => (string) ($d->frozen_amount ?? '0.00'), 'withdrawn_amount' => (string) ($d->withdrawn_amount ?? '0.00'),
            'team_level1' => $level1->count(),
            'team_level2' => $s['level'] === 2 && $level1->isNotEmpty() ? Member::query()->whereIn('parent_id', $level1)->count() : 0,
        ];
    }

    public function apply(int $memberId, string $realName, string $remark): void
    {
        $this->core->apply($memberId, $realName, $remark);
    }

    public function commissions(int $memberId, array $f): array
    {
        $p = DB::table('distribution_commission as c')->join('order as o', 'o.id', '=', 'c.order_id')->leftJoin('member as b', 'b.id', '=', 'c.buyer_id')
            ->where('c.member_id', $memberId)
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('c.status', (int) $f['status']))
            ->orderByDesc('c.id')->select('c.*', 'o.order_no', 'b.nickname')
            ->paginate(min(max((int) ($f['size'] ?? 10), 1), 50), ['*'], 'page', max((int) ($f['page'] ?? 1), 1));

        $p->through(fn ($r) => [
            'order_no' => $r->order_no, 'buyer' => self::maskName((string) $r->nickname), 'level' => (int) $r->level,
            'base_amount' => (string) $r->base_amount, 'rate' => (string) $r->rate, 'amount' => (string) $r->amount,
            'status' => (int) $r->status, 'status_text' => DistributionCommission::STATUS_TEXT[(int) $r->status] ?? '',
            'created_at' => (string) $r->created_at, 'settled_at' => (string) $r->settled_at,
        ]);

        return Paginate::format($p);
    }

    /** 我的团队：level=1 直接下级，level=2 下级的下级（只有开启二级分销时可看） */
    public function team(int $memberId, array $f): array
    {
        $level = (int) ($f['level'] ?? 1) === 2 && $this->core->settings()['level'] === 2 ? 2 : 1;
        $q = Member::query()->orderByDesc('id');
        $level === 1
            ? $q->where('parent_id', $memberId)
            : $q->whereIn('parent_id', Member::query()->where('parent_id', $memberId)->select('id'));
        $p = $q->paginate(min(max((int) ($f['size'] ?? 10), 1), 50), ['id', 'nickname', 'avatar', 'created_at'], 'page', max((int) ($f['page'] ?? 1), 1));

        $p->through(fn (Member $m) => [
            'nickname' => self::maskName((string) $m->nickname), 'avatar' => $m->avatar, 'joined_at' => $m->created_at?->format('Y-m-d'),
        ]);

        return Paginate::format($p);
    }

    public function withdraws(int $memberId, array $f): array
    {
        $p = DistributionWithdraw::query()->where('member_id', $memberId)->orderByDesc('id')
            ->paginate(min(max((int) ($f['size'] ?? 10), 1), 50), ['*'], 'page', max((int) ($f['page'] ?? 1), 1));

        $p->through(fn (DistributionWithdraw $w) => [
            'withdraw_no' => $w->withdraw_no, 'amount' => (string) $w->amount,
            'method' => $w->method, 'method_text' => DistributionWithdraw::METHOD_TEXT[$w->method] ?? $w->method,
            'status' => (int) $w->status, 'status_text' => DistributionWithdraw::STATUS_TEXT[(int) $w->status] ?? '',
            'reject_reason' => $w->reject_reason, 'created_at' => $w->created_at?->format('Y-m-d H:i:s'), 'paid_at' => $w->paid_at?->format('Y-m-d H:i:s'),
        ]);

        return Paginate::format($p);
    }

    public function withdrawApply(int $memberId, array $d): array
    {
        $w = $this->core->withdrawApply($memberId, (string) $d['amount'], (string) $d['method'], (array) $d['account']);

        return ['withdraw_no' => $w->withdraw_no, 'amount' => (string) $w->amount];
    }

    /** 团队成员和买家昵称只显示首尾，保护隐私 */
    private static function maskName(string $name): string
    {
        $len = mb_strlen($name);
        if ($len <= 1) {
            return $len === 1 ? $name . '*' : '***';
        }

        return mb_substr($name, 0, 1) . str_repeat('*', min($len - 2, 3) ?: 1) . ($len > 2 ? mb_substr($name, -1) : '');
    }
}
