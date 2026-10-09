<?php

namespace Tests\Feature;

use App\Models\OrderRefund;
use App\Services\Common\ConfigService;
use App\Services\Common\OrderRefundService;
use App\Support\Exceptions\BusinessException;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 售后与退款：整单退款、部分退款、退货退款、拒绝与撤销，以及库存、订单状态、积分的联动 */
class RefundFlowTest extends FeatureTestCase
{
    private function refunds(): OrderRefundService
    {
        return app(OrderRefundService::class);
    }

    private function refundId(string $refundNo): int
    {
        return (int) OrderRefund::query()->where('refund_no', $refundNo)->value('id');
    }

    /** 买家申请 → 商家同意 → 登记线下已退款 */
    private function completeRefund(string $token, string $orderNo, array $data): string
    {
        $r = $this->api('POST', "/orders/{$orderNo}/refunds", $data, $token);
        $this->assertSame(0, $r->json('code'), (string) $r->json('msg'));
        $id = $this->refundId($r->json('data.refund_no'));
        $this->refunds()->approve($id, 1);
        $this->refunds()->pay($id, 1, true);

        return $r->json('data.refund_no');
    }

    public function test_whole_order_refund_before_shipping_restores_everything(): void
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $sku = $this->makeSku('100.00', 10);
        $order = $this->pay($this->placeOrder($token, $sku, 2, $addr->id));
        $this->assertSame(8, $this->stock($sku));

        $info = $this->api('GET', "/orders/{$order->order_no}/refundable", [], $token);
        $this->assertTrue($info->json('data.whole_order.allowed'));
        $this->assertSame('200.00', $info->json('data.whole_order.amount'));

        $this->completeRefund($token, $order->order_no, ['type' => 1, 'reason' => '不想要了']);

        $order = $order->fresh();
        $this->assertSame([OrderStatus::CLOSED, OrderStatus::PAY_REFUNDED], [(int) $order->status, (int) $order->pay_status]);
        $this->assertSame(10, $this->stock($sku));
        $this->assertSame(0, (int) DB::table('goods_sku')->where('id', $sku)->value('sales'));
        $this->assertDatabaseHas('pay_trade', ['order_id' => $order->id, 'trade_type' => 2, 'channel' => 'offline', 'amount' => '200.00']);

        // 全额退完后不能再申请
        $this->api('POST', "/orders/{$order->order_no}/refunds", ['type' => 1, 'reason' => '再来一次'], $token)->assertJsonPath('code', 1);
    }

    public function test_order_is_locked_while_a_refund_is_open_and_released_when_it_ends(): void
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $sku = $this->makeSku('100.00', 10);
        $order = $this->pay($this->placeOrder($token, $sku, 3, $addr->id));
        $itemId = (int) DB::table('order_item')->where('order_id', $order->id)->value('id');

        // 申请后订单进入“售后中”，此时后台不能发货
        $r = $this->api('POST', "/orders/{$order->order_no}/refunds", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '少买一件'], $token);
        $this->assertSame('100.00', $r->json('data.refund_amount'));
        $this->assertSame(OrderStatus::AFTER_SALE, (int) $order->fresh()->status);
        $this->adminApi('POST', "/order/ship/{$order->id}", ['express_code' => 'SF', 'express_no' => 'SF1'])->assertJsonPath('code', 1);

        // 商家拒绝：订单回到待发货，金额和库存不变
        $this->refunds()->reject($this->refundId($r->json('data.refund_no')), 1, '不符合条件');
        $this->assertSame([OrderStatus::TO_SHIP, OrderStatus::PAY_PAID], [(int) $order->fresh()->status, (int) $order->fresh()->pay_status]);
        $this->assertSame(7, $this->stock($sku));

        // 再申请后买家自己撤销：同样回到待发货
        $r = $this->api('POST', "/orders/{$order->order_no}/refunds", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '再试'], $token);
        $this->api('POST', "/refunds/{$r->json('data.refund_no')}/cancel", [], $token)->assertJsonPath('code', 0);
        $this->assertSame(OrderStatus::TO_SHIP, (int) $order->fresh()->status);
    }

    public function test_partial_refunds_track_stock_status_and_remaining_amount(): void
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $sku = $this->makeSku('100.00', 10);
        $order = $this->pay($this->placeOrder($token, $sku, 3, $addr->id));
        $itemId = (int) DB::table('order_item')->where('order_id', $order->id)->value('id');
        $item = fn (int $qty, int $type) => ['order_item_id' => $itemId, 'qty' => $qty, 'type' => $type, 'reason' => '测试'];

        // 未发货不能选退货退款；数量、金额都不能超
        $this->api('POST', "/orders/{$order->order_no}/refunds", $item(1, 2), $token)->assertJsonPath('code', 1);
        $this->api('POST', "/orders/{$order->order_no}/refunds", $item(4, 1), $token)->assertJsonPath('code', 1);
        $this->api('POST', "/orders/{$order->order_no}/refunds", $item(1, 1) + ['amount' => '100.01'], $token)->assertJsonPath('code', 1);

        // ① 未发货仅退款 1 件：回补库存，订单回到待发货、部分退款
        $this->completeRefund($token, $order->order_no, $item(1, 1));
        $this->assertSame(8, $this->stock($sku));
        $this->assertSame([OrderStatus::TO_SHIP, OrderStatus::PAY_PART_REFUND], [(int) $order->fresh()->status, (int) $order->fresh()->pay_status]);

        // 部分退款后剩下的商品仍然可以发货
        $this->adminApi('POST', "/order/ship/{$order->id}", ['express_code' => 'SF', 'express_no' => 'SF1'])->assertJsonPath('code', 0);
        $this->assertSame(OrderStatus::SHIPPED, (int) $order->fresh()->status);

        // ② 已发货退货退款 1 件：要等买家寄回、商家确认收货后才能退款，完成时回补库存
        $r = $this->api('POST', "/orders/{$order->order_no}/refunds", $item(1, 2), $token);
        $id = $this->refundId($r->json('data.refund_no'));
        $this->refunds()->approve($id, 1);
        try {
            $this->refunds()->pay($id, 1, true);
            $this->fail('买家还没寄回，不应该能退款');
        } catch (BusinessException) {
            // 预期
        }
        $this->api('POST', "/refunds/{$r->json('data.refund_no')}/return", ['express' => '顺丰', 'express_no' => 'SF-BACK'], $token)->assertJsonPath('code', 0);
        $this->refunds()->confirmReturn($id, 1);
        $this->refunds()->pay($id, 1, true);
        $this->assertSame(9, $this->stock($sku));
        $this->assertSame(OrderStatus::SHIPPED, (int) $order->fresh()->status);

        // 同一张售后单不能退两次
        try {
            $this->refunds()->pay($id, 1, true);
            $this->fail('重复退款应被拒绝');
        } catch (BusinessException) {
            // 预期
        }

        // ③ 最后 1 件已发货仅退款：不回补库存；商品全部退完，订单关闭
        $this->completeRefund($token, $order->order_no, $item(1, 1));
        $this->assertSame(9, $this->stock($sku));
        $this->assertSame([OrderStatus::CLOSED, OrderStatus::PAY_REFUNDED], [(int) $order->fresh()->status, (int) $order->fresh()->pay_status]);
        $this->assertSame('300.00', (string) OrderRefund::query()->where('order_id', $order->id)->where('status', OrderRefund::DONE)->sum('refund_amount'));
    }

    public function test_merchant_may_lower_the_amount_but_never_exceed_what_was_paid(): void
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $sku = $this->makeSku('100.00', 10);
        $order = $this->pay($this->placeOrder($token, $sku, 2, $addr->id));
        $itemId = (int) DB::table('order_item')->where('order_id', $order->id)->value('id');

        $r = $this->api('POST', "/orders/{$order->order_no}/refunds", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => 'x'], $token);
        $id = $this->refundId($r->json('data.refund_no'));

        try {
            $this->refunds()->approve($id, 1, '200.01');
            $this->fail('退款金额超过实付应被拒绝');
        } catch (BusinessException) {
            // 预期
        }
        $this->refunds()->approve($id, 1, '60.00');
        $this->refunds()->pay($id, 1, true);

        $this->assertSame('60.00', (string) OrderRefund::query()->whereKey($id)->value('refund_amount'));
        // 订单还剩 140 可退：第二件的上限是按占比分摊的 100
        $info = $this->api('GET', "/orders/{$order->order_no}/refundable", [], $token);
        $this->assertSame('100.00', $info->json('data.items.0.max_amount'));
    }

    public function test_points_spent_on_an_order_come_back_when_it_is_cancelled_or_fully_refunded(): void
    {
        app(ConfigService::class)->save('trade', ['points_deduct_enabled' => 1, 'points_per_unit' => 100, 'points_max_percent' => 50]);
        [$m, $token] = $this->makeMember(['points' => 20000]);
        $addr = $this->makeAddress($m->id);
        $sku = $this->makeSku('100.00', 10);
        $points = fn () => (int) DB::table('member')->where('id', $m->id)->value('points');

        // 200 的商品最多抵 50% = 100，用掉 10000 积分
        $order = $this->placeOrder($token, $sku, 2, $addr->id, ['use_points' => true]);
        $this->assertSame(['100.00', '100.00'], [(string) $order->points_amount, (string) $order->pay_amount]);
        $this->assertSame(10000, $points());

        $this->api('POST', "/orders/{$order->order_no}/cancel", [], $token)->assertJsonPath('code', 0);
        $this->assertSame(20000, $points(), '取消后积分退回');

        // 再下一单并支付，整单退款后积分也退回
        $order = $this->pay($this->placeOrder($token, $sku, 2, $addr->id, ['use_points' => true]));
        $this->assertSame(10000, $points());
        $this->completeRefund($token, $order->order_no, ['type' => 1, 'reason' => '退']);
        $this->assertSame(20000, $points());
        $this->assertSame('100.00', (string) OrderRefund::query()->where('order_id', $order->id)->value('refund_amount'), '只退实付的 100，不退积分抵掉的部分');
    }

    public function test_refund_window_closes_after_the_configured_days(): void
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $sku = $this->makeSku('100.00', 10);
        $order = $this->pay($this->placeOrder($token, $sku, 1, $addr->id));
        $this->adminApi('POST', "/order/ship/{$order->id}", ['express_code' => 'SF', 'express_no' => 'SF1'])->assertJsonPath('code', 0);
        $this->api('POST', "/orders/{$order->order_no}/receive", [], $token)->assertJsonPath('code', 0);

        // 刚完成：还在 7 天售后期内
        $this->assertSame('', $this->api('GET', "/orders/{$order->order_no}/refundable", [], $token)->json('data.reason'));

        DB::table('order')->where('id', $order->id)->update(['finished_at' => now()->subDays(8)]);
        $info = $this->api('GET', "/orders/{$order->order_no}/refundable", [], $token);
        $this->assertStringContainsString('7 天', $info->json('data.reason'));
        $this->assertSame(0, $info->json('data.items.0.available_qty'));
    }
}
