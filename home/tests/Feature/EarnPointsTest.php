<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Common\ConfigService;
use App\Services\Common\OrderPointsService;
use App\Services\Common\OrderRefundService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FeatureTestCase;

/** 消费得积分：订单完成时发放、只发一次、退款扣回 */
class EarnPointsTest extends FeatureTestCase
{
    private int $memberId;

    private string $token;

    private int $addressId;

    private int $sku;

    protected function setUp(): void
    {
        parent::setUp();
        app(ConfigService::class)->save('trade', ['points_earn_enabled' => 1, 'points_earn_per_unit' => 2]);
        [$m, $this->token] = $this->makeMember();
        $this->memberId = (int) $m->id;
        $this->addressId = (int) $this->makeAddress($m->id)->id;
        $this->sku = $this->makeSku('100.00', 50);
    }

    private function points(): int
    {
        return (int) DB::table('member')->where('id', $this->memberId)->value('points');
    }

    private function buy(int $qty, array $extra = [], array $headers = []): Order
    {
        return $this->pay($this->placeOrder($this->token, $this->sku, $qty, $this->addressId, $extra, $headers));
    }

    private function shipAndReceive(Order $order): void
    {
        $this->adminApi('POST', "/order/ship/{$order->id}", ['express_code' => 'SF', 'express_no' => 'SF1'])->assertJsonPath('code', 0);
        $this->api('POST', "/orders/{$order->order_no}/receive", [], $this->token)->assertJsonPath('code', 0);
    }

    /** 买家申请 → 商家同意 → 登记线下已退款 */
    private function refund(Order $order, int $qty, ?string $amount = null): void
    {
        $itemId = (int) DB::table('order_item')->where('order_id', $order->id)->value('id');
        $r = $this->api('POST', "/orders/{$order->order_no}/refunds", ['order_item_id' => $itemId, 'qty' => $qty, 'type' => 1, 'reason' => '测试'], $this->token);
        $this->assertSame(0, $r->json('code'), (string) $r->json('msg'));
        $id = (int) OrderRefund::query()->where('refund_no', $r->json('data.refund_no'))->value('id');
        app(OrderRefundService::class)->approve($id, 1, $amount);
        app(OrderRefundService::class)->pay($id, 1, true);
    }

    public function test_points_are_granted_when_the_order_is_completed_not_when_it_is_paid(): void
    {
        $preview = $this->api('POST', '/orders/checkout', ['items' => [['sku_id' => $this->sku, 'qty' => 3]], 'address_id' => $this->addressId], $this->token);
        $this->assertSame(600, $preview->json('data.earn_points'));

        $order = $this->buy(3);
        $this->assertSame(0, $this->points(), '付款后还没到账');

        $this->shipAndReceive($order);
        $this->assertSame(600, $this->points());
        $this->assertDatabaseHas('member_point_log', ['member_id' => $this->memberId, 'type' => 3, 'change_points' => 600, 'after_points' => 600, 'ref_no' => $order->order_no]);

        // 同一订单重复触发不会再发
        DB::transaction(fn () => app(OrderPointsService::class)->earn($order->fresh()));
        $this->assertSame(600, $this->points());
    }

    public function test_shipping_fee_and_points_deduction_do_not_earn_and_other_currencies_are_converted(): void
    {
        $tpl = DB::table('shipping_template')->insertGetId(['name' => '测试模板', 'charge_type' => 1, 'is_free_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipping_template_region')->insert(['template_id' => $tpl, 'countries' => 'CN', 'provinces' => '', 'first_unit' => 1, 'first_fee' => 10, 'step_unit' => 1, 'step_fee' => 5, 'free_amount' => 0]);
        $this->sku = $this->makeSku('99.90', 50, ['shipping_template_id' => $tpl]);

        // 实付 109.90，其中运费 10：按 99.90 × 2 向下取整
        $this->shipAndReceive($this->buy(1));
        $this->assertSame(199, $this->points());

        // 美元订单按汇率折回基准币种再算，和人民币下单得到的积分相当
        $usd = $this->buy(1, [], ['X-Currency' => 'USD']);
        $this->shipAndReceive($usd);
        $earned = $this->points() - 199;
        $this->assertSame(OrderPointsService::earnCalc(Money::toBase(bcsub((string) $usd->pay_amount, (string) $usd->shipping_fee, 2), (string) $usd->rate), 2), $earned);
        $this->assertEqualsWithDelta(199, $earned, 2);

        // 用积分抵掉的部分不再送积分
        app(ConfigService::class)->save('trade', ['points_deduct_enabled' => 1, 'points_per_unit' => 10, 'points_max_percent' => 50]);
        DB::table('member')->where('id', $this->memberId)->update(['points' => 100]);
        $order = $this->buy(1, ['use_points' => true]);
        $this->assertSame(['10.00', 0], [(string) $order->points_amount, $this->points()]);
        $this->shipAndReceive($order);
        $this->assertSame(179, $this->points(), '(99.90 - 10) × 2 向下取整');
    }

    public function test_nothing_is_granted_when_the_feature_is_off(): void
    {
        app(ConfigService::class)->save('trade', ['points_earn_enabled' => 0]);

        $this->assertSame(0, $this->api('POST', '/orders/checkout', ['items' => [['sku_id' => $this->sku, 'qty' => 1]], 'address_id' => $this->addressId], $this->token)->json('data.earn_points'));
        $this->shipAndReceive($this->buy(1));

        $this->assertSame(0, $this->points());
    }

    public function test_a_refund_before_completion_reduces_what_is_granted(): void
    {
        $order = $this->buy(3);
        $this->refund($order, 1);
        $this->shipAndReceive($order->fresh());

        $this->assertSame(400, $this->points());
    }

    public function test_refunds_after_completion_claw_points_back_proportionally(): void
    {
        $order = $this->buy(4);
        $this->shipAndReceive($order);
        $this->assertSame(800, $this->points());

        // 退 1 件（四分之一）
        $this->refund($order, 1);
        $this->assertSame(600, $this->points());
        $this->assertDatabaseHas('member_point_log', ['member_id' => $this->memberId, 'type' => 6, 'change_points' => -200, 'after_points' => 600, 'ref_no' => $order->order_no]);
        // 售后结束、订单回到“已完成”不会重新发放
        $this->assertSame(1, DB::table('member_point_log')->where('ref_no', $order->order_no)->where('type', 3)->count());

        // 再退 1 件，商家只同意退 50：剩余商品款 300 → 250，保留 600 × 250 / 300 = 500
        $this->refund($order, 1, '50.00');
        $this->assertSame(500, $this->points());

        // 剩下 2 件全退：全部扣回
        $this->refund($order, 2);
        $this->assertSame(0, $this->points());
    }

    public function test_clawback_never_drives_the_balance_below_zero(): void
    {
        $order = $this->buy(2);
        $this->shipAndReceive($order);
        $this->assertSame(400, $this->points());

        // 买家已经把积分花掉了一部分
        DB::table('member')->where('id', $this->memberId)->update(['points' => 50]);
        $this->refund($order, 2);

        $this->assertSame(0, $this->points());
        $this->assertDatabaseHas('member_point_log', ['member_id' => $this->memberId, 'type' => 6, 'change_points' => -50, 'after_points' => 0]);
    }

    #[DataProvider('keepCases')]
    public function test_keep_after_refund(int $kept, string $leftAfter, string $refund, bool $all, int $expected): void
    {
        $this->assertSame($expected, OrderPointsService::keepAfterRefund($kept, $leftAfter, $refund, $all));
    }

    public static function keepCases(): array
    {
        return [
            '退一半' => [800, '200.00', '200.00', false, 400],
            '不整除向下取整' => [100, '200.00', '100.00', false, 66],
            '商品全部退完' => [800, '50.00', '50.00', true, 0],
            '剩余商品款为 0' => [800, '0.00', '100.00', false, 0],
            '没有可保留的积分' => [0, '100.00', '100.00', false, 0],
        ];
    }
}
