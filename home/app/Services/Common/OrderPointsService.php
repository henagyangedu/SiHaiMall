<?php

namespace App\Services\Common;

use App\Models\Member;
use App\Models\MemberPointLog;
use App\Models\Order;
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * 下单积分抵扣（结算预览与下单共用同一套计算）。
 *
 * 规则（交易设置里配置，默认关闭）：
 * - points_per_unit 个积分抵 1 元基准币种；最多抵扣「商品金额 - 会员折扣」的 points_max_percent%，运费不参与抵扣。
 * - 勾选使用积分时，在上限内尽量多用；抵扣金额按下单币种固化在订单 points_amount。
 * - 实际扣了多少积分记在积分流水（type=4，ref_no=订单号），订单取消、超时关闭或商品全部退款时按流水净额退还。
 */
class OrderPointsService
{
    /** 积分流水类型：下单抵扣（退还时记正数） */
    private const TYPE_DEDUCT = 4;

    public function __construct(private ConfigService $config) {}

    /**
     * 本单最多可用多少积分、能抵多少钱。
     * @param string $goodsNet 商品金额 - 会员折扣（下单币种）
     * @return array{enabled:bool,available:int,points:int,amount:string} amount 为下单币种
     */
    public function quote(Member $member, string $goodsNet, string $rate, int $decimals): array
    {
        $trade = $this->config->get('trade');
        $out = ['enabled' => (int) ($trade['points_deduct_enabled'] ?? 0) === 1, 'available' => (int) $member->points, 'points' => 0, 'amount' => '0.00'];
        if (!$out['enabled'] || $out['available'] <= 0 || bccomp($goodsNet, '0', 2) <= 0) {
            return $out;
        }

        $r = self::calc($out['available'], Money::toBase($goodsNet, $rate), (int) $trade['points_per_unit'], (int) $trade['points_max_percent']);
        $amount = Money::fromBase($r['base'], $rate, $decimals);
        // 币种换算的舍入不能让抵扣金额达到或超过商品金额（订单应付至少要有 0.01）
        if ($r['points'] <= 0 || bccomp($amount, '0', 2) <= 0 || bccomp($amount, $goodsNet, 2) >= 0) {
            return $out;
        }

        return ['points' => $r['points'], 'amount' => $amount] + $out;
    }

    /**
     * 纯计算：在上限内最多用多少积分、抵多少基准币种金额（抵扣金额截断到分，积分按实际抵扣金额向上取整）。
     * @return array{points:int,base:string}
     */
    public static function calc(int $available, string $baseAmount, int $perUnit, int $maxPercent): array
    {
        if ($available <= 0 || $perUnit <= 0 || $maxPercent <= 0 || bccomp($baseAmount, '0', 2) <= 0) {
            return ['points' => 0, 'base' => '0.00'];
        }
        $cap = bcdiv(bcmul($baseAmount, (string) min($maxPercent, 100), 4), '100', 2);
        $capPoints = (int) bcmul($cap, (string) $perUnit, 0);
        $base = bcdiv((string) min($available, $capPoints), (string) $perUnit, 2);
        if (bccomp($base, '0', 2) <= 0) {
            return ['points' => 0, 'base' => '0.00'];
        }
        $exact = bcmul($base, (string) $perUnit, 2);
        $points = (int) bcadd($exact, '0', 0) + (bccomp($exact, bcadd($exact, '0', 0), 2) > 0 ? 1 : 0);

        return ['points' => $points, 'base' => $base];
    }

    /** 下单时扣积分。必须在事务内调用 */
    public function spend(int $memberId, int $points, string $orderNo): void
    {
        if ($points <= 0) {
            return;
        }
        $m = Member::query()->lockForUpdate()->find($memberId) ?? throw new BusinessException('会员不存在');
        if ((int) $m->points < $points) {
            throw new BusinessException('积分不足，请刷新后重试');
        }
        $this->change($m, -$points, $orderNo, '下单抵扣');
    }

    /** 退还订单抵扣的积分：按流水净额退，重复调用不会多退。必须在事务内调用 */
    public function giveBack(Order $order, string $remark): void
    {
        $net = (int) DB::table('member_point_log')->where('ref_no', $order->order_no)->where('type', self::TYPE_DEDUCT)->sum('change_points');
        if ($net >= 0) {
            return;
        }
        $m = Member::query()->lockForUpdate()->find($order->member_id);
        if ($m) {
            $this->change($m, -$net, $order->order_no, $remark);
        }
    }

    private function change(Member $m, int $delta, string $orderNo, string $remark): void
    {
        $after = (int) $m->points + $delta;
        $m->update(['points' => $after]);
        MemberPointLog::query()->create([
            'member_id' => $m->id, 'type' => self::TYPE_DEDUCT, 'change_points' => $delta, 'after_points' => $after,
            'ref_no' => $orderNo, 'remark' => $remark, 'admin_id' => 0, 'created_at' => now(),
        ]);
    }
}
