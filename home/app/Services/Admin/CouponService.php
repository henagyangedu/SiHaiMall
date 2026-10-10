<?php

namespace App\Services\Admin;

use App\Models\Coupon;
use App\Models\Member;
use App\Models\MemberCoupon;
use App\Services\Common\CouponService as CouponRules;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/**
 * 后台的优惠券管理：建券、改券、停用、删除、发放给指定会员、查看领取和使用记录。
 * 已经有人领过的券，面额、门槛、类型、适用范围、有效期不能再改（买家领到手的不该变），只能改名称、发行量、领取设置。
 */
class CouponService
{
    /** 有人领过之后还能改的字段 */
    private const EDITABLE_AFTER_RECEIVED = ['name', 'total', 'per_member', 'receive_start', 'receive_end', 'is_public', 'status'];

    public function __construct(private CouponRules $rules) {}

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));

        $p = Coupon::query()
            ->when($kw !== '', fn ($q) => $q->where('name', 'like', '%' . addcslashes($kw, '%_\\') . '%'))
            ->when(($f['type'] ?? '') !== '' && $f['type'] !== null, fn ($q) => $q->where('type', (int) $f['type']))
            ->when(($f['status'] ?? '') !== '' && $f['status'] !== null, fn ($q) => $q->where('status', (int) $f['status']))
            ->orderByDesc('id')->paginate($size);

        $used = MemberCoupon::query()->whereIn('coupon_id', collect($p->items())->pluck('id'))->where('status', MemberCoupon::USED)
            ->groupBy('coupon_id')->selectRaw('coupon_id, COUNT(*) as c')->pluck('c', 'coupon_id');
        $p->through(fn (Coupon $c) => $this->row($c) + ['used' => (int) ($used[$c->id] ?? 0)]);

        return Paginate::format($p);
    }

    public function create(array $d): Coupon
    {
        return Coupon::query()->create($this->fields($d));
    }

    public function update(int $id, array $d): void
    {
        $c = $this->find($id);
        $fields = $this->fields($d);
        if ((int) $c->received > 0) {
            // 已经有人领了：核心设置如果和原来不一样就拒绝，而不是悄悄忽略
            $before = $this->comparable($c->only(array_keys($fields)));
            $after = $this->comparable($fields);
            foreach (array_diff(array_keys($fields), self::EDITABLE_AFTER_RECEIVED) as $key) {
                if ($before[$key] !== $after[$key]) {
                    throw new BusinessException('这张券已经有人领取，面额、门槛、适用范围和有效期不能再改；需要调整请停用后新建一张');
                }
            }
            $fields = array_intersect_key($fields, array_flip(self::EDITABLE_AFTER_RECEIVED));
            if ((int) $fields['total'] > 0 && (int) $fields['total'] < (int) $c->received) {
                throw new BusinessException("发行总量不能少于已领取的数量（{$c->received}）");
            }
        }
        $c->update($fields);
    }

    public function setStatus(int $id, int $status): void
    {
        $this->find($id)->update(['status' => $status === 1 ? 1 : 0]);
    }

    /** 只有没人领过的券能删；有人领过的用“停用”（停用后不能再领，已经领到的照常能用） */
    public function delete(int $id): void
    {
        $c = $this->find($id);
        if ((int) $c->received > 0) {
            throw new BusinessException('这张券已经有人领取，不能删除，可以停用');
        }
        $c->delete();
    }

    /**
     * 发给指定会员，每人一张。$accounts 每一项可以是会员 ID、账号、手机号或邮箱。
     * 有任何一个找不到就整批不发，免得发了一半不知道谁没收到。
     * @return array{granted:int}
     */
    public function grant(int $id, array $accounts): array
    {
        $accounts = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $accounts), fn ($v) => $v !== '')));
        if ($accounts === []) {
            throw new BusinessException('请填写要发放的会员');
        }
        if (count($accounts) > 500) {
            throw new BusinessException('一次最多发给 500 位会员');
        }
        $ids = [];
        $missing = [];
        foreach ($accounts as $a) {
            $m = Member::query()->where(function ($q) use ($a) {
                $q->where('account', $a)->orWhere('email', $a)->orWhere('phone', $a);
                if (ctype_digit($a) && strlen($a) < 10) {
                    $q->orWhere('id', (int) $a);
                }
            })->first(['id', 'status']);
            if (!$m || (int) $m->status !== 1) {
                $missing[] = $a;
            } else {
                $ids[(int) $m->id] = true;
            }
        }
        if ($missing !== []) {
            throw new BusinessException('找不到这些会员（或已被禁用）：' . implode('、', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' 等 ' . count($missing) . ' 个' : ''));
        }

        return DB::transaction(function () use ($id, $ids) {
            $c = Coupon::query()->lockForUpdate()->find($id) ?? throw new BusinessException('优惠券不存在');
            if ((int) $c->total > 0 && (int) $c->total - (int) $c->received < count($ids)) {
                throw new BusinessException('剩余数量不够：还剩 ' . ((int) $c->total - (int) $c->received) . ' 张，要发 ' . count($ids) . ' 张');
            }
            foreach (array_keys($ids) as $memberId) {
                $this->rules->issue($c, $memberId, false);
            }

            return ['granted' => count($ids)];
        });
    }

    /** 一张券的领取和使用记录 */
    public function records(int $id, array $f): array
    {
        $this->find($id);
        $p = DB::table('member_coupon as mc')
            ->leftJoin('member as m', 'm.id', '=', 'mc.member_id')->leftJoin('order as o', 'o.id', '=', 'mc.order_id')
            ->where('mc.coupon_id', $id)
            ->when(($f['status'] ?? '') !== '' && $f['status'] !== null, fn ($q) => $q->where('mc.status', (int) $f['status']))
            ->orderByDesc('mc.id')->select('mc.*', 'm.nickname', 'm.account', 'o.order_no')
            ->paginate(min(max((int) ($f['size'] ?? 15), 1), 100));

        $now = now();
        $p->through(function ($r) use ($now) {
            // 过期状态由定时任务批量更新，这里按有效期即时判断，免得列表里显示“未使用”其实已经过期
            $status = (int) $r->status === MemberCoupon::UNUSED && $now->gte($r->valid_end) ? MemberCoupon::EXPIRED : (int) $r->status;

            return [
                'id' => (int) $r->id, 'member_id' => (int) $r->member_id, 'member' => ($r->nickname ?: $r->account) ?: ('#' . $r->member_id),
                'status' => $status, 'status_text' => MemberCoupon::STATUS_TEXT[$status] ?? '',
                'source' => (int) $r->source, 'source_text' => (int) $r->source === MemberCoupon::SOURCE_ADMIN ? '后台发放' : '自己领取',
                'valid_start' => (string) $r->valid_start, 'valid_end' => (string) $r->valid_end,
                'order_no' => (string) ($r->order_no ?? ''), 'used_at' => $r->used_at ? (string) $r->used_at : null, 'created_at' => (string) $r->created_at,
            ];
        });

        return Paginate::format($p);
    }

    // ------------------------------------------------------------------ 内部
    private function find(int $id): Coupon
    {
        return Coupon::query()->find($id) ?? throw new BusinessException('优惠券不存在');
    }

    private function row(Coupon $c): array
    {
        return [
            'id' => (int) $c->id, 'name' => $c->name, 'type' => (int) $c->type, 'type_text' => Coupon::TYPE_TEXT[(int) $c->type] ?? '',
            'desc' => CouponRules::describe($c),
            'threshold' => (string) $c->threshold, 'amount' => (string) $c->amount, 'discount' => (string) $c->discount, 'max_amount' => (string) $c->max_amount,
            'scope' => (int) $c->scope, 'scope_text' => Coupon::SCOPE_TEXT[(int) $c->scope] ?? '', 'scope_ids' => array_map('intval', (array) ($c->scope_ids ?? [])),
            'total' => (int) $c->total, 'received' => (int) $c->received, 'per_member' => (int) $c->per_member,
            'valid_type' => (int) $c->valid_type, 'valid_start' => $c->valid_start?->format('Y-m-d H:i:s'), 'valid_end' => $c->valid_end?->format('Y-m-d H:i:s'),
            'valid_days' => (int) $c->valid_days,
            'receive_start' => $c->receive_start?->format('Y-m-d H:i:s'), 'receive_end' => $c->receive_end?->format('Y-m-d H:i:s'),
            'is_public' => (int) $c->is_public, 'status' => (int) $c->status, 'created_at' => $c->created_at?->format('Y-m-d H:i:s'),
            // 固定有效期的券过了结束时间就没用了，列表里标出来
            'ended' => (int) $c->valid_type === Coupon::VALID_FIXED && $c->valid_end && $c->valid_end->isPast(),
        ];
    }

    /** 表单提交的内容 → 要保存的字段，同时做跨字段的检查 */
    private function fields(array $d): array
    {
        $type = (int) $d['type'];
        $scope = (int) ($d['scope'] ?? Coupon::SCOPE_ALL);
        $validType = (int) ($d['valid_type'] ?? Coupon::VALID_FIXED);
        $threshold = bcadd((string) ($d['threshold'] ?? '0'), '0', 2);
        $amount = bcadd((string) ($d['amount'] ?? '0'), '0', 2);

        if ($type === Coupon::TYPE_REDUCE) {
            if (bccomp($amount, '0', 2) <= 0) {
                throw new BusinessException('请填写满减金额');
            }
            // 有门槛时，减的不能比门槛还多（否则等于白送还倒贴）
            if (bccomp($threshold, '0', 2) > 0 && bccomp($amount, $threshold, 2) >= 0) {
                throw new BusinessException('满减金额要小于使用门槛');
            }
        } else {
            $discount = (string) ($d['discount'] ?? '0');
            if (bccomp($discount, '1', 2) < 0 || bccomp($discount, '99', 2) > 0) {
                throw new BusinessException('折扣请填 1 到 99 之间的数，90 表示 9 折');
            }
        }

        $scopeIds = [];
        if ($scope !== Coupon::SCOPE_ALL) {
            $scopeIds = array_values(array_unique(array_filter(array_map('intval', (array) ($d['scope_ids'] ?? [])))));
            if ($scopeIds === []) {
                throw new BusinessException($scope === Coupon::SCOPE_CATEGORY ? '请选择适用的分类' : '请选择适用的商品');
            }
            $table = $scope === Coupon::SCOPE_CATEGORY ? 'category' : 'goods';
            if (DB::table($table)->whereIn('id', $scopeIds)->whereNull('deleted_at')->count() !== count($scopeIds)) {
                throw new BusinessException($scope === Coupon::SCOPE_CATEGORY ? '选择的分类里有不存在的' : '选择的商品里有不存在的');
            }
        }

        if ($validType === Coupon::VALID_FIXED) {
            if (empty($d['valid_start']) || empty($d['valid_end'])) {
                throw new BusinessException('请填写有效期的开始和结束时间');
            }
            if (strtotime((string) $d['valid_end']) <= strtotime((string) $d['valid_start'])) {
                throw new BusinessException('有效期的结束时间必须晚于开始时间');
            }
        } elseif ((int) ($d['valid_days'] ?? 0) < 1) {
            throw new BusinessException('请填写领取后多少天内有效');
        }
        if (!empty($d['receive_start']) && !empty($d['receive_end']) && strtotime((string) $d['receive_end']) <= strtotime((string) $d['receive_start'])) {
            throw new BusinessException('领取的结束时间必须晚于开始时间');
        }

        return [
            'name' => trim((string) $d['name']), 'type' => $type, 'threshold' => $threshold,
            'amount' => $type === Coupon::TYPE_REDUCE ? $amount : '0.00',
            'discount' => $type === Coupon::TYPE_DISCOUNT ? bcadd((string) $d['discount'], '0', 2) : '0.00',
            'max_amount' => $type === Coupon::TYPE_DISCOUNT ? bcadd((string) ($d['max_amount'] ?? '0'), '0', 2) : '0.00',
            'scope' => $scope, 'scope_ids' => $scopeIds,
            'total' => (int) ($d['total'] ?? 0), 'per_member' => max(1, (int) ($d['per_member'] ?? 1)),
            'valid_type' => $validType,
            'valid_start' => $validType === Coupon::VALID_FIXED ? $d['valid_start'] : null,
            'valid_end' => $validType === Coupon::VALID_FIXED ? $d['valid_end'] : null,
            'valid_days' => $validType === Coupon::VALID_DAYS ? (int) $d['valid_days'] : 0,
            'receive_start' => !empty($d['receive_start']) ? $d['receive_start'] : null,
            'receive_end' => !empty($d['receive_end']) ? $d['receive_end'] : null,
            'is_public' => (int) ($d['is_public'] ?? 1) === 1 ? 1 : 0, 'status' => (int) ($d['status'] ?? 1) === 1 ? 1 : 0,
        ];
    }

    /** 把字段值整理成可以直接比较的形式（日期统一格式、数字统一小数位、数组排序） */
    private function comparable(array $fields): array
    {
        foreach ($fields as $k => $v) {
            if ($v instanceof \DateTimeInterface) {
                $v = $v->format('Y-m-d H:i:s');
            } elseif (is_array($v)) {
                sort($v);
                $v = implode(',', $v);
            } elseif (in_array($k, ['threshold', 'amount', 'discount', 'max_amount'], true)) {
                $v = bcadd((string) $v, '0', 2);
            } elseif (in_array($k, ['valid_start', 'valid_end'], true) && $v !== null) {
                $v = date('Y-m-d H:i:s', strtotime((string) $v));
            }
            $fields[$k] = $v === null ? '' : (string) $v;
        }

        return $fields;
    }
}
