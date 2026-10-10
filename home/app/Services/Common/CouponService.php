<?php

namespace App\Services\Common;

use App\Models\Coupon;
use App\Models\MemberCoupon;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 优惠券的核心规则（后台发放、买家领取、下单用券都经过这里）。
 *
 * - 类型：满减券（满 threshold 减 amount）、折扣券（满 threshold 打 discount 折，最多减 max_amount）。
 * - 适用范围：全部商品 / 指定分类（含下级）/ 指定商品。门槛和折扣都只按适用范围内的商品金额算。
 * - 有效期在领到手的那一刻固化到 gm_member_coupon，之后后台再改券的设置，不影响已经领到的。
 * - 发行量：received 在发一张的同一条 UPDATE 里加一并校验没有超发（靠行锁，不会超发）。
 */
class CouponService
{
    /**
     * 给一个会员发一张券。$bySelf=true 是买家自己领（要求券公开可领、在领取时间内、没超过每人限领）；
     * false 是后台发放（只受发行总量限制）。必须在事务里调用。
     */
    public function issue(Coupon $coupon, int $memberId, bool $bySelf): MemberCoupon
    {
        if ((int) $coupon->status !== 1) {
            throw new BusinessException('这张优惠券已停用');
        }
        $now = now();
        [$start, $end] = self::validPeriod($coupon, $now);
        if ($end->lte($now)) {
            throw new BusinessException('这张优惠券已过期');
        }
        if ($bySelf) {
            if ((int) $coupon->is_public !== 1) {
                throw new BusinessException('这张优惠券不能领取');
            }
            if (($coupon->receive_start && $coupon->receive_start->gt($now)) || ($coupon->receive_end && $coupon->receive_end->lte($now))) {
                throw new BusinessException('不在领取时间内');
            }
            if (MemberCoupon::query()->where('member_id', $memberId)->where('coupon_id', $coupon->id)->count() >= (int) $coupon->per_member) {
                throw new BusinessException('已经领过了');
            }
        }

        // 发行量：加一和“没超发”的判断放在同一条语句里，同时来多少人都不会超发
        $ok = DB::table('coupon')->where('id', $coupon->id)
            ->where(fn ($q) => $q->where('total', 0)->orWhereColumn('received', '<', 'total'))
            ->update(['received' => DB::raw('received + 1')]);
        if (!$ok) {
            throw new BusinessException('优惠券已经领完了');
        }

        return MemberCoupon::query()->create([
            'member_id' => $memberId, 'coupon_id' => $coupon->id, 'status' => MemberCoupon::UNUSED,
            'valid_start' => $start, 'valid_end' => $end,
            'source' => $bySelf ? MemberCoupon::SOURCE_SELF : MemberCoupon::SOURCE_ADMIN, 'created_at' => $now,
        ]);
    }

    /**
     * 这张券现在领的话，有效期是哪一段。
     * @return array{0:Carbon,1:Carbon}
     */
    public static function validPeriod(Coupon $coupon, Carbon $now): array
    {
        if ((int) $coupon->valid_type === Coupon::VALID_DAYS) {
            return [$now->copy(), $now->copy()->addDays(max(1, (int) $coupon->valid_days))];
        }

        return [$coupon->valid_start->copy(), $coupon->valid_end->copy()];
    }

    /** 给买家和后台看的一句话说明，如“满 300 减 30”“满 200 打 9 折，最多减 50” */
    public static function describe(Coupon $c): string
    {
        $threshold = bccomp((string) $c->threshold, '0', 2) > 0 ? '满 ' . self::trim((string) $c->threshold) . ' ' : '无门槛';
        if ((int) $c->type === Coupon::TYPE_REDUCE) {
            return $threshold . '减 ' . self::trim((string) $c->amount);
        }
        $zhe = self::trim(bcdiv((string) $c->discount, '10', 2));

        return $threshold . "打 {$zhe} 折" . (bccomp((string) $c->max_amount, '0', 2) > 0 ? '，最多减 ' . self::trim((string) $c->max_amount) : '');
    }

    /** 去掉金额末尾多余的 0：30.00 → 30，9.50 → 9.5 */
    private static function trim(string $n): string
    {
        return str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;
    }
}
