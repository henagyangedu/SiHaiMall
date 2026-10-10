<?php

namespace App\Services\Common;

use App\Models\DistributionCommission;
use App\Models\DistributionLog;
use App\Models\DistributionWithdraw;
use App\Models\Distributor;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * 分销的核心规则（关系、佣金、结算、提现的唯一入口）。金额一律为基准币种。
 *
 * - 关系：注册时凭邀请码绑定推荐人（member.parent_id），之后会员不能自己改，后台可改。
 * - 层级：分销设置里配 1 级或 2 级。一级 = 买家的直接上级，二级 = 上级的上级。
 *         上级不是“正常”状态的分销员就跳过，不往上顺延；买家自己是分销员也不给自己返佣。
 * - 计佣：订单支付成功时生成“待结算”佣金。基数 = 商品金额 - 会员折扣 - 积分抵扣（不含运费），折成基准币种。
 * - 退款：按已退的商品款占比扣减佣金；商品全部退完佣金作废。已结算的佣金也会扣回（可提现余额可能为负）。
 * - 结算：订单完成且过了售后申请期限、没有进行中的售后，佣金转为可提现（定时任务 mall:distribution-settle）。
 * - 提现：申请时可提现余额转入“提现中”；后台通过后线下打款并登记；驳回则退还。
 */
class DistributionService
{
    public function __construct(private ConfigService $config, private NotifyService $notify) {}

    /** @return array{enabled:bool,level:int,rate_1:string,rate_2:string,min_withdraw:string} */
    public function settings(): array
    {
        $c = $this->config->get('distribution');

        return [
            'enabled' => (int) $c['enabled'] === 1, 'level' => (int) $c['level'] === 2 ? 2 : 1,
            'rate_1' => self::num($c['rate_1']), 'rate_2' => self::num($c['rate_2']), 'min_withdraw' => self::num($c['min_withdraw']),
        ];
    }

    // ------------------------------------------------------------------ 邀请码与关系
    /** 邀请码 = 会员 ID 的 36 进制 + 2 位校验（防止随手改一位就猜到别人的码） */
    public static function inviteCode(int $memberId): string
    {
        $body = strtoupper(base_convert((string) $memberId, 10, 36));

        return $body . self::checksum($body);
    }

    public static function decodeInviteCode(string $code): ?int
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[0-9A-Z]{3,15}$/', $code)) {
            return null;
        }
        $body = substr($code, 0, -2);
        if (self::checksum($body) !== substr($code, -2)) {
            return null;
        }
        $id = (int) base_convert($body, 36, 10);

        return $id > 0 ? $id : null;
    }

    /** 注册时绑定推荐人：邀请码无效、推荐人不存在或被禁用时直接报错，避免用户以为绑定成功 */
    public function parentIdFromInvite(string $code): int
    {
        $id = self::decodeInviteCode($code) ?? throw new BusinessException('邀请码不正确');
        $parent = Member::query()->find($id);
        if (!$parent || (int) $parent->status !== 1) {
            throw new BusinessException('邀请码不正确');
        }

        return $id;
    }

    /** 后台调整上级：0 表示清除。不能形成环（自己或自己的下级不能成为自己的上级） */
    public function setParent(int $memberId, int $parentId): void
    {
        $m = Member::query()->find($memberId) ?? throw new BusinessException('会员不存在');
        if ($parentId > 0) {
            if ($parentId === $memberId) {
                throw new BusinessException('不能把自己设为上级');
            }
            Member::query()->find($parentId) ?? throw new BusinessException('上级会员不存在');
            for ($cur = $parentId, $i = 0; $cur > 0 && $i < 100; $i++) {
                $cur = (int) Member::query()->whereKey($cur)->value('parent_id');
                if ($cur === $memberId) {
                    throw new BusinessException('该会员是目标上级的上级，不能互为上下级');
                }
            }
        }
        $m->forceFill(['parent_id' => $parentId])->save();
    }

    // ------------------------------------------------------------------ 分销员申请与审核
    public function apply(int $memberId, string $realName, string $remark): Distributor
    {
        if (!$this->settings()['enabled']) {
            throw new BusinessException('分销功能未开启');
        }

        return DB::transaction(function () use ($memberId, $realName, $remark) {
            $d = Distributor::query()->lockForUpdate()->find($memberId);
            if ($d && in_array((int) $d->status, [Distributor::PENDING, Distributor::NORMAL, Distributor::DISABLED], true)) {
                throw new BusinessException(match ((int) $d->status) {
                    Distributor::PENDING => '申请正在审核中，请耐心等待',
                    Distributor::NORMAL => '您已经是分销员',
                    default => '分销资格已被停用，请联系客服',
                });
            }
            $data = ['status' => Distributor::PENDING, 'real_name' => mb_substr(trim($realName), 0, 60), 'apply_remark' => mb_substr(trim($remark), 0, 255), 'reject_reason' => ''];
            if ($d) {
                $d->update($data);

                return $d;
            }

            return Distributor::query()->create(['member_id' => $memberId] + $data);
        });
    }

    public function audit(int $memberId, bool $pass, string $rejectReason = ''): void
    {
        DB::transaction(function () use ($memberId, $pass, $rejectReason) {
            $d = Distributor::query()->lockForUpdate()->find($memberId) ?? throw new BusinessException('分销申请不存在');
            if ((int) $d->status !== Distributor::PENDING) {
                throw new BusinessException('该申请已处理过（' . Distributor::STATUS_TEXT[(int) $d->status] . '）');
            }
            $d->update($pass
                ? ['status' => Distributor::NORMAL, 'approved_at' => now(), 'reject_reason' => '']
                : ['status' => Distributor::REJECTED, 'reject_reason' => mb_substr(trim($rejectReason), 0, 255)]);
            $this->syncFlag($memberId, $pass);
        });
    }

    /** 停用 / 恢复分销员。停用后不再产生新佣金，已有佣金和提现照常处理 */
    public function setEnabled(int $memberId, bool $enabled): void
    {
        DB::transaction(function () use ($memberId, $enabled) {
            $d = Distributor::query()->lockForUpdate()->find($memberId) ?? throw new BusinessException('分销员不存在');
            if (!in_array((int) $d->status, [Distributor::NORMAL, Distributor::DISABLED], true)) {
                throw new BusinessException('只有已通过审核的分销员才能停用或恢复');
            }
            $d->update(['status' => $enabled ? Distributor::NORMAL : Distributor::DISABLED]);
            $this->syncFlag($memberId, $enabled);
        });
    }

    /** 后台直接指定为分销员（跳过申请） */
    public function appoint(int $memberId): void
    {
        Member::query()->find($memberId) ?? throw new BusinessException('会员不存在');
        DB::transaction(function () use ($memberId) {
            $d = Distributor::query()->lockForUpdate()->find($memberId);
            $data = ['status' => Distributor::NORMAL, 'approved_at' => now(), 'reject_reason' => ''];
            $d ? $d->update($data) : Distributor::query()->create(['member_id' => $memberId] + $data);
            $this->syncFlag($memberId, true);
        });
    }

    // ------------------------------------------------------------------ 佣金：生成 / 退款扣减 / 结算
    /** 订单支付成功时调用（事务内）。同一订单同一层级只会生成一条，重复调用无副作用 */
    public function onOrderPaid(Order $order): void
    {
        $s = $this->settings();
        if (!$s['enabled']) {
            return;
        }
        $base = self::commissionBase($order);
        if (bccomp($base, '0', 2) <= 0) {
            return;
        }

        // 各商品行的金额和商品上的佣金设置（取支付这一刻的设置，之后再改商品不影响已生成的佣金）
        $lines = DB::table('order_item as oi')->leftJoin('goods as g', 'g.id', '=', 'oi.goods_id')->where('oi.order_id', $order->id)
            ->get(['oi.price', 'oi.qty', 'g.commission_type', 'g.commission_rate_1', 'g.commission_rate_2'])
            ->map(fn ($r) => [
                'amount' => Money::mul((string) $r->price, (int) $r->qty), 'type' => (int) ($r->commission_type ?? 0),
                'rate_1' => (string) ($r->commission_rate_1 ?? '0'), 'rate_2' => (string) ($r->commission_rate_2 ?? '0'),
            ])->all();

        $parentId = (int) Member::query()->whereKey($order->member_id)->value('parent_id');
        $firstBeneficiary = 0;
        for ($level = 1; $level <= $s['level'] && $parentId > 0; $level++) {
            ['base' => $levelBase, 'rate' => $rate, 'amount' => $amount] = self::commissionByGoods($base, $lines, $level, $s['rate_' . $level]);
            $d = Distributor::query()->lockForUpdate()->find($parentId);
            if ($d && (int) $d->status === Distributor::NORMAL && bccomp($amount, '0', 2) > 0
                && !DistributionCommission::query()->where('order_id', $order->id)->where('level', $level)->exists()) {
                DistributionCommission::query()->create([
                    'order_id' => $order->id, 'buyer_id' => $order->member_id, 'member_id' => $parentId, 'level' => $level,
                    'base_amount' => $levelBase, 'rate' => $rate, 'origin_amount' => $amount, 'amount' => $amount, 'status' => DistributionCommission::PENDING,
                ]);
                $d->update(['pending_amount' => bcadd((string) $d->pending_amount, $amount, 2)]);
                $firstBeneficiary = $firstBeneficiary ?: $parentId;
            }
            $parentId = (int) Member::query()->whereKey($parentId)->value('parent_id');
        }

        if ($firstBeneficiary > 0) {
            Order::query()->whereKey($order->id)->update(['source_member_id' => $firstBeneficiary]);
        }
    }

    /** 售后退款完成后调用（事务内）：按订单最新的已退金额重算佣金，可重复调用 */
    public function onRefund(Order $order): void
    {
        $rows = DistributionCommission::query()->where('order_id', $order->id)
            ->whereIn('status', [DistributionCommission::PENDING, DistributionCommission::SETTLED])->lockForUpdate()->get();
        if ($rows->isEmpty()) {
            return;
        }

        $refunded = (string) OrderRefund::query()->where('order_id', $order->id)->where('status', OrderRefund::DONE)->sum('refund_amount');
        $allRefunded = !DB::table('order_item')->where('order_id', $order->id)->whereColumn('refund_qty', '<', 'qty')->exists();
        $goodsPaid = bcsub((string) $order->pay_amount, (string) $order->shipping_fee, 2);

        foreach ($rows as $c) {
            $target = $allRefunded ? '0.00' : self::afterRefund((string) $c->origin_amount, $goodsPaid, $refunded);
            $diff = bcsub((string) $c->amount, $target, 2);
            if (bccomp($diff, '0', 2) <= 0 && !$allRefunded) {
                continue;
            }
            $d = Distributor::query()->lockForUpdate()->find($c->member_id);
            if ($d && bccomp($diff, '0', 2) > 0) {
                if ((int) $c->status === DistributionCommission::PENDING) {
                    $d->update(['pending_amount' => bcsub((string) $d->pending_amount, $diff, 2)]);
                } else {
                    // 已结算的佣金扣回：可提现余额可能为负，由后续佣金抵扣
                    $this->changeAvailable($d, '-' . $diff, DistributionLog::REFUND, $order->order_no, '订单退款扣回佣金');
                }
            }
            $c->update(['amount' => $target] + ($allRefunded ? ['status' => DistributionCommission::VOID] : []));
        }
    }

    /**
     * 结算到期的佣金，返回结算条数。到期 = 订单已完成、完成时间超过售后申请期限、没有进行中的售后。
     * 每条独立事务，单条失败不影响其它。
     */
    public function settleDue(int $limit = 500): int
    {
        $days = (int) ($this->config->value('trade', 'after_sale_days') ?? 7);
        $ids = DB::table('distribution_commission as c')->join('order as o', 'o.id', '=', 'c.order_id')
            ->where('c.status', DistributionCommission::PENDING)->where('o.status', OrderStatus::FINISHED)
            ->where('o.finished_at', '<=', now()->subDays($days))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('order_refund as r')->whereColumn('r.order_id', 'c.order_id')->whereIn('r.status', OrderRefund::ACTIVE))
            ->orderBy('c.id')->limit($limit)->pluck('c.id');

        $n = 0;
        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, &$n) {
                    $c = DistributionCommission::query()->lockForUpdate()->find($id);
                    if (!$c || (int) $c->status !== DistributionCommission::PENDING) {
                        return;
                    }
                    $d = Distributor::query()->lockForUpdate()->find($c->member_id);
                    if (!$d) {
                        return;
                    }
                    $orderNo = (string) DB::table('order')->where('id', $c->order_id)->value('order_no');
                    $d->update(['pending_amount' => bcsub((string) $d->pending_amount, (string) $c->amount, 2)]);
                    $this->changeAvailable($d, (string) $c->amount, DistributionLog::SETTLE, $orderNo, "订单佣金结算（{$c->level} 级）");
                    $c->update(['status' => DistributionCommission::SETTLED, 'settled_at' => now()]);
                    $n++;
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ 提现
    /** @param array<string,string> $account 收款信息（户名、账号、开户行等，加密保存） */
    public function withdrawApply(int $memberId, string $amount, string $method, array $account): DistributionWithdraw
    {
        $s = $this->settings();
        $amount = Money::round(number_format((float) $amount, 4, '.', ''), 2);
        if (!isset(DistributionWithdraw::METHOD_TEXT[$method])) {
            throw new BusinessException('不支持的提现方式');
        }
        if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, $s['min_withdraw'], 2) < 0) {
            throw new BusinessException("提现金额不能低于 {$s['min_withdraw']}");
        }

        return DB::transaction(function () use ($memberId, $amount, $method, $account) {
            $d = Distributor::query()->lockForUpdate()->find($memberId);
            if (!$d || !in_array((int) $d->status, [Distributor::NORMAL, Distributor::DISABLED], true)) {
                throw new BusinessException('您还不是分销员');
            }
            if (bccomp($amount, (string) $d->available_amount, 2) > 0) {
                throw new BusinessException("可提现佣金不足（当前 {$d->available_amount}）");
            }
            if (DistributionWithdraw::query()->where('member_id', $memberId)->whereIn('status', [DistributionWithdraw::PENDING, DistributionWithdraw::APPROVED])->exists()) {
                throw new BusinessException('您有一笔提现正在处理中，请等待完成后再申请');
            }

            $no = 'WD' . date('ymdHis') . random_int(100000, 999999);
            $w = DistributionWithdraw::query()->create([
                'withdraw_no' => $no, 'member_id' => $memberId, 'amount' => $amount, 'method' => $method,
                'account_info' => array_map(fn ($v) => mb_substr(trim((string) $v), 0, 200), $account), 'status' => DistributionWithdraw::PENDING,
            ]);
            $d->update(['frozen_amount' => bcadd((string) $d->frozen_amount, $amount, 2)]);
            $this->changeAvailable($d, '-' . $amount, DistributionLog::WITHDRAW, $no, '申请提现');

            return $w;
        });
    }

    public function withdrawAudit(int $id, bool $pass, string $rejectReason, int $adminId): void
    {
        DB::transaction(function () use ($id, $pass, $rejectReason, $adminId) {
            $w = DistributionWithdraw::query()->lockForUpdate()->find($id) ?? throw new BusinessException('提现申请不存在');
            // 待打款的也允许驳回（比如收款信息有误、打款失败）
            $from = $pass ? [DistributionWithdraw::PENDING] : [DistributionWithdraw::PENDING, DistributionWithdraw::APPROVED];
            if (!in_array((int) $w->status, $from, true)) {
                throw new BusinessException('该提现申请当前状态不能这样处理（' . DistributionWithdraw::STATUS_TEXT[(int) $w->status] . '）');
            }
            if ($pass) {
                $w->update(['status' => DistributionWithdraw::APPROVED, 'admin_id' => $adminId]);

                return;
            }
            $d = Distributor::query()->lockForUpdate()->find($w->member_id) ?? throw new BusinessException('分销员不存在');
            $d->update(['frozen_amount' => bcsub((string) $d->frozen_amount, (string) $w->amount, 2)]);
            $this->changeAvailable($d, (string) $w->amount, DistributionLog::WITHDRAW_BACK, $w->withdraw_no, '提现被驳回，退还佣金');
            $w->update(['status' => DistributionWithdraw::REJECTED, 'reject_reason' => mb_substr(trim($rejectReason), 0, 255), 'admin_id' => $adminId]);
        });
    }

    /** 登记已打款（线下转账后操作） */
    public function withdrawPaid(int $id, string $payRemark, int $adminId): void
    {
        DB::transaction(function () use ($id, $payRemark, $adminId) {
            $w = DistributionWithdraw::query()->lockForUpdate()->find($id) ?? throw new BusinessException('提现申请不存在');
            if ((int) $w->status !== DistributionWithdraw::APPROVED) {
                throw new BusinessException('只有“待打款”的提现才能登记打款（当前：' . DistributionWithdraw::STATUS_TEXT[(int) $w->status] . '）');
            }
            $d = Distributor::query()->lockForUpdate()->find($w->member_id) ?? throw new BusinessException('分销员不存在');
            $d->update([
                'frozen_amount' => bcsub((string) $d->frozen_amount, (string) $w->amount, 2),
                'withdrawn_amount' => bcadd((string) $d->withdrawn_amount, (string) $w->amount, 2),
            ]);
            $w->update(['status' => DistributionWithdraw::PAID, 'pay_remark' => mb_substr(trim($payRemark), 0, 255), 'admin_id' => $adminId, 'paid_at' => now()]);
            $this->notify->send('withdraw_paid', (int) $w->member_id, ['withdraw_no' => $w->withdraw_no, 'amount' => (string) $w->amount]);
        });
    }

    // ------------------------------------------------------------------ 纯计算
    /** 计佣基数：商品金额 - 套餐优惠 - 会员折扣 - 优惠券 - 积分抵扣（不含运费），折成基准币种 */
    public static function commissionBase(Order $order): string
    {
        $net = bcsub(bcsub(bcsub(bcsub((string) $order->goods_amount, (string) ($order->bundle_amount ?? '0'), 2), (string) $order->discount_amount, 2), (string) $order->coupon_amount, 2), (string) $order->points_amount, 2);

        return bccomp($net, '0', 2) > 0 ? Money::toBase($net, (string) $order->rate) : '0.00';
    }

    public static function commissionAmount(string $base, string $rate): string
    {
        return Money::round(bcdiv(bcmul($base, $rate, 6), '100', 6), 2);
    }

    /**
     * 一张订单某一层级的佣金：把整单计佣基数按各商品行金额占比摊开，每行乘以自己的比例后加总。
     * 商品设置 type：0 跟随全局比例，1 用商品自己的 rate_1 / rate_2，2 不参与分销（不计入基数）。
     * 返回的 rate 用于展示：各行比例相同时就是该比例，否则是折算出的综合比例（佣金 ÷ 基数）。
     * @param array<int,array{amount:string,type:int,rate_1:string,rate_2:string}> $lines amount 为行金额（单价 × 数量）
     * @return array{base:string,rate:string,amount:string}
     */
    public static function commissionByGoods(string $base, array $lines, int $level, string $globalRate): array
    {
        // 先按比例把行金额归组（精确到分），再按组摊基数：只有一种比例时基数不会因为逐行摊分而产生舍入误差
        $total = '0.00';
        $groups = [];
        foreach ($lines as $l) {
            $total = bcadd($total, $l['amount'], 2);
            if ($l['type'] === 2) {
                continue;
            }
            $rate = self::num($l['type'] === 1 ? $l['rate_' . $level] : $globalRate);
            $groups[$rate] = bcadd($groups[$rate] ?? '0.00', $l['amount'], 2);
        }
        if (bccomp($total, '0', 2) <= 0 || bccomp($base, '0', 2) <= 0 || $groups === []) {
            return ['base' => '0.00', 'rate' => '0.00', 'amount' => '0.00'];
        }

        $partBase = $amount = '0';
        foreach ($groups as $rate => $groupAmount) {
            $share = bcdiv(bcmul($base, $groupAmount, 10), $total, 10);
            $partBase = bcadd($partBase, $share, 10);
            $amount = bcadd($amount, bcdiv(bcmul($share, (string) $rate, 10), '100', 10), 10);
        }
        $partBase = Money::round($partBase, 2);
        $amount = Money::round($amount, 2);
        $rate = count($groups) === 1
            ? (string) array_key_first($groups)
            : (bccomp($partBase, '0', 2) > 0 ? Money::round(bcdiv(bcmul($amount, '100', 10), $partBase, 10), 2) : '0.00');

        return ['base' => $partBase, 'rate' => self::num($rate), 'amount' => $amount];
    }

    /** 退款后的佣金 = 原佣金 × 未退的商品款占比（已退金额超过商品款时按全退算） */
    public static function afterRefund(string $original, string $goodsPaid, string $refunded): string
    {
        if (bccomp($goodsPaid, '0', 2) <= 0) {
            return '0.00';
        }
        $left = bcsub($goodsPaid, $refunded, 2);
        if (bccomp($left, '0', 2) <= 0) {
            return '0.00';
        }

        return Money::round(bcdiv(bcmul($original, $left, 6), $goodsPaid, 6), 2);
    }

    // ------------------------------------------------------------------ 内部
    /** 可提现余额变动并写流水。$delta 带符号 */
    private function changeAvailable(Distributor $d, string $delta, int $type, string $refNo, string $remark): void
    {
        $after = bcadd((string) $d->available_amount, $delta, 2);
        $d->update(['available_amount' => $after]);
        DistributionLog::query()->create([
            'member_id' => $d->member_id, 'type' => $type, 'change_amount' => $delta, 'after_amount' => $after,
            'ref_no' => $refNo, 'remark' => $remark, 'created_at' => now(),
        ]);
    }

    /** 会员表上的分销员标记与分销员状态保持一致（预留字段，便于列表筛选） */
    private function syncFlag(int $memberId, bool $on): void
    {
        Member::query()->whereKey($memberId)->update(['distributor_status' => $on ? 1 : 0]);
    }

    private static function checksum(string $body): string
    {
        $c = base_convert(substr(hash_hmac('sha256', $body, (string) config('app.key')), 0, 8), 16, 36);

        return strtoupper(substr(str_pad($c, 2, '0', STR_PAD_LEFT), -2));
    }

    private static function num(mixed $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }
}
