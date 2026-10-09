<?php

namespace App\Services\Common;

use App\Models\Member;
use App\Models\MemberPointLog;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * 订单积分：下单抵扣（结算预览与下单共用同一套计算）和消费得积分。
 *
 * 抵扣规则（交易设置里配置，默认关闭）：
 * - points_per_unit 个积分抵 1 元基准币种；最多抵扣「商品金额 - 会员折扣」的 points_max_percent%，运费不参与抵扣。
 * - 勾选使用积分时，在上限内尽量多用；抵扣金额按下单币种固化在订单 points_amount。
 * - 实际扣了多少积分记在积分流水（type=4，ref_no=订单号），订单取消、超时关闭或商品全部退款时按流水净额退还。
 *
 * 消费得积分（交易设置里配置，默认关闭）：
 * - 订单完成时发放：按「实付 - 运费 - 已退款」折基准币种 × points_earn_per_unit，向下取整；同一订单只发一次（流水 type=3）。
 * - 完成后再退款：按本次退款占剩余商品款的比例扣回（流水 type=6），商品全部退完则扣光；会员积分不够时扣到 0 为止。
 */
class OrderPointsService
{
    /** 积分流水类型：下单抵扣（退还时记正数） */
    private const TYPE_DEDUCT = 4;

    /** 积分流水类型：消费获得 / 退款扣回 */
    private const TYPE_EARN = 3;

    private const TYPE_CLAWBACK = 6;

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

    // ------------------------------------------------------------------ 消费得积分
    /**
     * 这笔钱预计能得多少积分（结算预览用）。未开启时为 0。
     * @param string $goodsPaid 商品实付（下单币种，不含运费）
     */
    public function earnQuote(string $goodsPaid, string $rate): int
    {
        $trade = $this->config->get('trade');
        if ((int) ($trade['points_earn_enabled'] ?? 0) !== 1) {
            return 0;
        }

        return self::earnCalc(Money::toBase($goodsPaid, $rate), (int) $trade['points_earn_per_unit']);
    }

    /** 纯计算：基准币种金额 × 每元赠送积分，向下取整 */
    public static function earnCalc(string $baseAmount, int $perUnit): int
    {
        if ($perUnit <= 0 || bccomp($baseAmount, '0', 2) <= 0) {
            return 0;
        }

        return (int) bcmul($baseAmount, (string) $perUnit, 0);
    }

    /** 订单完成时发放积分：同一订单只发一次，重复调用无副作用。必须在事务内调用 */
    public function earn(Order $order): void
    {
        if (DB::table('member_point_log')->where('ref_no', $order->order_no)->where('type', self::TYPE_EARN)->exists()) {
            return;
        }
        $refunded = (string) OrderRefund::query()->where('order_id', $order->id)->where('status', OrderRefund::DONE)->sum('refund_amount');
        $left = bcsub(self::goodsPaid($order), $refunded, 2);
        $points = bccomp($left, '0', 2) > 0 ? $this->earnQuote($left, (string) $order->rate) : 0;
        if ($points <= 0) {
            return;
        }
        $m = Member::query()->lockForUpdate()->find($order->member_id);
        if ($m) {
            $this->change($m, $points, $order->order_no, '订单完成赠送积分', self::TYPE_EARN);
        }
    }

    /**
     * 订单完成后又退款：扣回对应的赠送积分。每张售后单完成时调用一次，必须在事务内调用（售后单此时已是“已完成”）。
     * 保留积分 = 当前保留 × 退款后剩余商品款 ÷ 退款前剩余商品款；商品全部退完则不保留。
     */
    public function clawBack(Order $order, OrderRefund $refund): void
    {
        $kept = (int) DB::table('member_point_log')->where('ref_no', $order->order_no)
            ->whereIn('type', [self::TYPE_EARN, self::TYPE_CLAWBACK])->sum('change_points');
        if ($kept <= 0) {
            return;
        }

        $refunded = (string) OrderRefund::query()->where('order_id', $order->id)->where('status', OrderRefund::DONE)->sum('refund_amount');
        $allRefunded = !DB::table('order_item')->where('order_id', $order->id)->whereColumn('refund_qty', '<', 'qty')->exists();
        $take = $kept - self::keepAfterRefund($kept, bcsub(self::goodsPaid($order), $refunded, 2), (string) $refund->refund_amount, $allRefunded);
        if ($take <= 0) {
            return;
        }
        $m = Member::query()->lockForUpdate()->find($order->member_id);
        $take = $m ? min($take, max((int) $m->points, 0)) : 0;
        if ($take > 0) {
            $this->change($m, -$take, $order->order_no, "售后 {$refund->refund_no} 退款，扣回赠送积分", self::TYPE_CLAWBACK);
        }
    }

    /**
     * 纯计算：一次退款之后这笔订单还能保留多少赠送积分（向下取整）。
     * @param string $leftAfter 退款后剩余的商品款
     * @param string $refundAmount 本次退款金额
     */
    public static function keepAfterRefund(int $kept, string $leftAfter, string $refundAmount, bool $allRefunded): int
    {
        if ($allRefunded || $kept <= 0 || bccomp($leftAfter, '0', 2) <= 0) {
            return 0;
        }
        $leftBefore = bcadd($leftAfter, $refundAmount, 2);

        return min($kept, (int) bcdiv(bcmul((string) $kept, $leftAfter, 2), $leftBefore, 0));
    }

    /** 商品实付 = 实付 - 运费（下单币种） */
    private static function goodsPaid(Order $order): string
    {
        return bcsub((string) $order->pay_amount, (string) $order->shipping_fee, 2);
    }

    private function change(Member $m, int $delta, string $orderNo, string $remark, int $type = self::TYPE_DEDUCT): void
    {
        $after = (int) $m->points + $delta;
        $m->update(['points' => $after]);
        MemberPointLog::query()->create([
            'member_id' => $m->id, 'type' => $type, 'change_points' => $delta, 'after_points' => $after,
            'ref_no' => $orderNo, 'remark' => $remark, 'admin_id' => 0, 'created_at' => now(),
        ]);
    }
}
