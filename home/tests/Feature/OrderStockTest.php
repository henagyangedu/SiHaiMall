<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Common\OrderCloseService;
use App\Services\Common\OrderStockService;
use App\Support\Exceptions\BusinessException;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 下单与库存：扣减、回补的幂等、超卖保护、超时关闭 */
class OrderStockTest extends FeatureTestCase
{
    public function test_placing_an_order_deducts_stock_and_snapshots_the_price(): void
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $sku = $this->makeSku('99.90', 10);

        $order = $this->placeOrder($token, $sku, 3, $addr->id);

        $this->assertSame(7, $this->stock($sku));
        $this->assertSame('299.70', (string) $order->goods_amount);
        $this->assertSame('299.70', (string) $order->pay_amount);
        $this->assertSame(OrderStatus::PENDING, (int) $order->status);

        // 下单后商品改价、改名，订单里的快照不变
        DB::table('goods_sku')->where('id', $sku)->update(['price' => '1.00']);
        DB::table('goods_lang')->where('goods_id', DB::table('goods_sku')->where('id', $sku)->value('goods_id'))->update(['title' => '改过的名字']);
        $item = DB::table('order_item')->where('order_id', $order->id)->first();
        $this->assertSame('99.90', (string) $item->price);
        $this->assertStringStartsWith('测试商品', $item->title);

        $this->assertDatabaseHas('stock_log', ['ref_no' => $order->order_no, 'sku_id' => $sku, 'type' => 1, 'change_qty' => -3, 'after_qty' => 7]);
    }

    public function test_client_cannot_dictate_the_price(): void
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $sku = $this->makeSku('99.90', 10);

        // 客户端夹带价格字段：服务端只认自己算的价
        $r = $this->api('POST', '/orders', ['items' => [['sku_id' => $sku, 'qty' => 1, 'price' => '0.01']], 'address_id' => $addr->id, 'pay_amount' => '0.01'], $token);

        $this->assertSame('99.90', $r->json('data.pay_amount'));
    }

    public function test_ordering_more_than_stock_is_rejected_and_leaves_no_trace(): void
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $sku = $this->makeSku('50.00', 2);

        $r = $this->api('POST', '/orders', ['items' => [['sku_id' => $sku, 'qty' => 3]], 'address_id' => $addr->id], $token);

        $this->assertSame(1, $r->json('code'));
        $this->assertStringContainsString('库存不足', $r->json('msg'));
        $this->assertSame(2, $this->stock($sku));
        $this->assertSame(0, Order::query()->where('member_id', $m->id)->count());
        $this->assertSame(0, DB::table('stock_log')->where('sku_id', $sku)->count());
    }

    public function test_cancelling_restores_stock_exactly_once(): void
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $sku = $this->makeSku('50.00', 10);
        $order = $this->placeOrder($token, $sku, 4, $addr->id);
        $this->assertSame(6, $this->stock($sku));

        $this->api('POST', "/orders/{$order->order_no}/cancel", ['reason' => '不想要了'], $token)->assertJsonPath('code', 0);
        $this->assertSame(10, $this->stock($sku));
        $this->assertSame(OrderStatus::CANCELLED, (int) $order->fresh()->status);

        // 回补按流水净额计算：重复回补不会多补
        DB::transaction(fn () => app(OrderStockService::class)->restore($order->order_no));
        $this->assertSame(10, $this->stock($sku));

        // 已取消的订单不能再取消
        $this->api('POST', "/orders/{$order->order_no}/cancel", [], $token)->assertJsonPath('code', 1);
        $this->assertSame(10, $this->stock($sku));
    }

    public function test_expired_unpaid_orders_are_closed_by_the_scheduled_command(): void
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $sku = $this->makeSku('50.00', 10);
        $expired = $this->placeOrder($token, $sku, 2, $addr->id);
        $fresh = $this->placeOrder($token, $sku, 1, $addr->id);
        Order::query()->whereKey($expired->id)->update(['expire_at' => now()->subMinute()]);

        Artisan::call('mall:close-expired-orders');

        $this->assertSame(OrderStatus::CLOSED, (int) $expired->fresh()->status);
        $this->assertSame(OrderStatus::PENDING, (int) $fresh->fresh()->status);
        $this->assertSame(9, $this->stock($sku), '只回补超时那一单的 2 件');

        // 命令重复执行、或对已关闭的订单再关一次，都不会重复回补
        Artisan::call('mall:close-expired-orders');
        try {
            app(OrderCloseService::class)->closeUnpaid($expired->fresh(), OrderStatus::CLOSED, OrderStatus::OP_SYSTEM, 0, '重复关闭');
            $this->fail('已关闭的订单不应该能再次关闭');
        } catch (BusinessException) {
            // 预期：状态已变化
        }
        $this->assertSame(9, $this->stock($sku));
    }

    public function test_checkout_preview_and_the_real_order_agree_including_shipping_region_price_and_currency(): void
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id, ['province' => '广东省', 'city' => '深圳市']);

        // 运费模板：国内首件 10、续件 5（基准币种）
        $tpl = DB::table('shipping_template')->insertGetId(['name' => '测试模板', 'charge_type' => 1, 'is_free_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipping_template_region')->insert(['template_id' => $tpl, 'countries' => 'CN', 'provinces' => '', 'first_unit' => 1, 'first_fee' => 10, 'step_unit' => 1, 'step_fee' => 5, 'free_amount' => 0]);
        $sku = $this->makeSku('99.90', 10, ['shipping_template_id' => $tpl]);

        // 销售区域：深圳，区域价 79.90
        $region = DB::table('sale_region')->insertGetId(['name' => '深圳', 'scope' => 1, 'currency' => 'CNY', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sale_region_area')->insert(['region_id' => $region, 'country_code' => 'CN', 'province' => '广东省', 'city' => '深圳市']);
        DB::table('goods_region_price')->insert(['sku_id' => $sku, 'region_id' => $region, 'price' => '79.90']);

        $body = ['items' => [['sku_id' => $sku, 'qty' => 2]], 'address_id' => $addr->id];
        foreach ([['CNY', '159.80', '15.00', '174.80'], ['USD', '22.38', '2.10', '24.48']] as [$currency, $goods, $shipping, $pay]) {
            $preview = $this->api('POST', '/orders/checkout', $body, $token, ['X-Currency' => $currency]);
            $this->assertSame([$goods, $shipping, $pay], [$preview->json('data.goods_amount'), $preview->json('data.shipping_fee'), $preview->json('data.pay_amount')], "{$currency} 结算预览");
            $this->assertSame('深圳', $preview->json('data.price_region.name'));

            $order = $this->placeOrder($token, $sku, 2, $addr->id, [], ['X-Currency' => $currency]);
            $this->assertSame([$goods, $shipping, $pay], [(string) $order->goods_amount, (string) $order->shipping_fee, (string) $order->pay_amount], "{$currency} 实际下单");
            $this->assertSame([$currency, '深圳'], [$order->currency, $order->price_region_name]);
        }
    }

    public function test_address_outside_every_shipping_rule_blocks_the_order(): void
    {
        [$m, $token] = $this->makeMember();
        $tpl = DB::table('shipping_template')->insertGetId(['name' => '只发国内', 'charge_type' => 1, 'is_free_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipping_template_region')->insert(['template_id' => $tpl, 'countries' => 'CN', 'provinces' => '', 'first_unit' => 1, 'first_fee' => 10, 'step_unit' => 1, 'step_fee' => 5, 'free_amount' => 0]);
        $sku = $this->makeSku('50.00', 5, ['shipping_template_id' => $tpl]);
        $us = $this->makeAddress($m->id, ['country_code' => 'US', 'province' => 'Texas', 'city' => 'Austin', 'phone_area' => '1']);

        $preview = $this->api('POST', '/orders/checkout', ['items' => [['sku_id' => $sku, 'qty' => 1]], 'address_id' => $us->id], $token);
        $this->assertNotEmpty($preview->json('data.problems'));

        $this->api('POST', '/orders', ['items' => [['sku_id' => $sku, 'qty' => 1]], 'address_id' => $us->id], $token)->assertJsonPath('code', 1);
        $this->assertSame(5, $this->stock($sku));
    }
}
