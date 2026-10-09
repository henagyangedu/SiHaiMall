<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\DistributionCommission;
use App\Models\DistributionWithdraw;
use App\Models\Distributor;
use App\Models\Member;
use App\Services\Common\DistributionService as Core;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/** 后台分销管理：分销员、佣金明细、提现。规则见 Common\DistributionService */
class DistributionService
{
    public function __construct(private Core $core) {}

    // ------------------------------------------------------------------ 分销员
    public function distributors(array $f): array
    {
        $kw = trim((string) ($f['keyword'] ?? ''));
        $p = DB::table('distributor as d')->join('member as m', 'm.id', '=', 'd.member_id')->leftJoin('member as p', 'p.id', '=', 'm.parent_id')
            ->when($kw !== '', function ($q) use ($kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->where(function ($w) use ($like, $kw) {
                    $w->where('m.nickname', 'like', $like)->orWhere('m.account', 'like', $like)->orWhere('m.phone', 'like', $like)->orWhere('d.real_name', 'like', $like);
                    if (ctype_digit($kw)) {
                        $w->orWhere('d.member_id', (int) $kw);
                    }
                });
            })
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('d.status', (int) $f['status']))
            // 待审核（状态 0）排最前
            ->orderBy('d.status')->orderByDesc('d.created_at')
            ->select('d.*', 'm.nickname', 'm.account', 'm.parent_id', 'p.nickname as parent_nickname', 'p.account as parent_account')
            ->paginate(min(max((int) ($f['size'] ?? 15), 1), 100));

        $ids = collect($p->items())->pluck('member_id')->all();
        $team = DB::table('member')->whereIn('parent_id', $ids)->whereNull('deleted_at')->groupBy('parent_id')->selectRaw('parent_id, count(*) as c')->pluck('c', 'parent_id');

        $p->through(fn ($r) => [
            'member_id' => (int) $r->member_id, 'member' => ($r->nickname ?: $r->account) ?: '#' . $r->member_id, 'real_name' => $r->real_name,
            'invite_code' => Core::inviteCode((int) $r->member_id),
            'parent_id' => (int) $r->parent_id, 'parent' => (int) $r->parent_id > 0 ? (($r->parent_nickname ?: $r->parent_account) ?: '#' . $r->parent_id) : '',
            'status' => (int) $r->status, 'status_text' => Distributor::STATUS_TEXT[(int) $r->status] ?? '',
            'apply_remark' => $r->apply_remark, 'reject_reason' => $r->reject_reason,
            'pending_amount' => (string) $r->pending_amount, 'available_amount' => (string) $r->available_amount,
            'frozen_amount' => (string) $r->frozen_amount, 'withdrawn_amount' => (string) $r->withdrawn_amount,
            'team_count' => (int) ($team[$r->member_id] ?? 0),
            'created_at' => (string) $r->created_at, 'approved_at' => (string) $r->approved_at,
        ]);

        return Paginate::format($p);
    }

    public function audit(int $memberId, bool $pass, string $rejectReason): void
    {
        if (!$pass && trim($rejectReason) === '') {
            throw new BusinessException('请填写拒绝原因');
        }
        $this->core->audit($memberId, $pass, $rejectReason);
    }

    public function setEnabled(int $memberId, bool $enabled): void
    {
        $this->core->setEnabled($memberId, $enabled);
    }

    public function appoint(int $memberId): void
    {
        $this->core->appoint($memberId);
    }

    /** 查看某个会员当前的上级（调整上级前确认用） */
    public function relation(int $memberId): array
    {
        $m = Member::query()->find($memberId) ?? throw new BusinessException('会员不存在');
        $p = (int) $m->parent_id > 0 ? Member::query()->find($m->parent_id) : null;

        return [
            'member_id' => (int) $m->id, 'member' => $m->nickname ?: $m->account,
            'parent_id' => (int) $m->parent_id, 'parent' => $p ? ($p->nickname ?: $p->account) : '',
        ];
    }

    public function setParent(int $memberId, int $parentId): void
    {
        $this->core->setParent($memberId, $parentId);
    }

    // ------------------------------------------------------------------ 佣金明细
    public function commissions(array $f): array
    {
        $kw = trim((string) ($f['keyword'] ?? ''));
        $p = DB::table('distribution_commission as c')->join('order as o', 'o.id', '=', 'c.order_id')
            ->leftJoin('member as m', 'm.id', '=', 'c.member_id')->leftJoin('member as b', 'b.id', '=', 'c.buyer_id')
            ->when($kw !== '', function ($q) use ($kw) {
                $like = addcslashes($kw, '%_\\') . '%';
                $q->where(function ($w) use ($like, $kw) {
                    $w->where('o.order_no', 'like', $like)->orWhere('m.nickname', 'like', '%' . $like)->orWhere('m.account', 'like', '%' . $like);
                    if (ctype_digit($kw)) {
                        $w->orWhere('c.member_id', (int) $kw);
                    }
                });
            })
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('c.status', (int) $f['status']))
            ->when(!empty($f['member_id']), fn ($q) => $q->where('c.member_id', (int) $f['member_id']))
            ->orderByDesc('c.id')
            ->select('c.*', 'o.order_no', 'm.nickname', 'm.account', 'b.nickname as buyer_nickname', 'b.account as buyer_account')
            ->paginate(min(max((int) ($f['size'] ?? 15), 1), 100));

        $p->through(fn ($r) => [
            'id' => (int) $r->id, 'order_no' => $r->order_no,
            'member_id' => (int) $r->member_id, 'member' => ($r->nickname ?: $r->account) ?: '#' . $r->member_id,
            'buyer' => ($r->buyer_nickname ?: $r->buyer_account) ?: '#' . $r->buyer_id, 'level' => (int) $r->level,
            'base_amount' => (string) $r->base_amount, 'rate' => (string) $r->rate, 'amount' => (string) $r->amount,
            'status' => (int) $r->status, 'status_text' => DistributionCommission::STATUS_TEXT[(int) $r->status] ?? '',
            'created_at' => (string) $r->created_at, 'settled_at' => (string) $r->settled_at,
        ]);

        return Paginate::format($p);
    }

    // ------------------------------------------------------------------ 提现
    public function withdraws(array $f): array
    {
        $kw = trim((string) ($f['keyword'] ?? ''));
        $p = DistributionWithdraw::query()
            ->when($kw !== '', function ($q) use ($kw) {
                $like = addcslashes($kw, '%_\\') . '%';
                $q->where(function ($w) use ($like, $kw) {
                    $w->where('withdraw_no', 'like', $like)
                        ->orWhereIn('member_id', DB::table('member')->where('nickname', 'like', '%' . $like)->orWhere('account', 'like', '%' . $like)->select('id'));
                    if (ctype_digit($kw)) {
                        $w->orWhere('member_id', (int) $kw);
                    }
                });
            })
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('status', (int) $f['status']))
            ->orderByRaw('status in (0, 1) desc')->orderByDesc('id')
            ->paginate(min(max((int) ($f['size'] ?? 15), 1), 100));

        $members = DB::table('member')->whereIn('id', collect($p->items())->pluck('member_id'))->get(['id', 'nickname', 'account'])->keyBy('id');
        $admins = DB::table('admin')->whereIn('id', collect($p->items())->pluck('admin_id')->filter())->pluck('username', 'id');

        $p->through(function (DistributionWithdraw $w) use ($members, $admins) {
            $m = $members->get($w->member_id);

            return [
                'id' => (int) $w->id, 'withdraw_no' => $w->withdraw_no,
                'member_id' => (int) $w->member_id, 'member' => $m ? ($m->nickname ?: $m->account) : '#' . $w->member_id,
                'amount' => (string) $w->amount, 'method' => $w->method, 'method_text' => DistributionWithdraw::METHOD_TEXT[$w->method] ?? $w->method,
                'account_info' => (array) $w->account_info,
                'status' => (int) $w->status, 'status_text' => DistributionWithdraw::STATUS_TEXT[(int) $w->status] ?? '',
                'reject_reason' => $w->reject_reason, 'pay_remark' => $w->pay_remark, 'operator' => (string) ($admins[$w->admin_id] ?? ''),
                'created_at' => $w->created_at?->format('Y-m-d H:i:s'), 'paid_at' => $w->paid_at?->format('Y-m-d H:i:s'),
            ];
        });

        return Paginate::format($p);
    }

    public function withdrawAudit(int $id, bool $pass, string $rejectReason, Admin $op): void
    {
        if (!$pass && trim($rejectReason) === '') {
            throw new BusinessException('请填写驳回原因');
        }
        $this->core->withdrawAudit($id, $pass, $rejectReason, (int) $op->id);
    }

    public function withdrawPaid(int $id, string $payRemark, Admin $op): void
    {
        $this->core->withdrawPaid($id, $payRemark, (int) $op->id);
    }
}
