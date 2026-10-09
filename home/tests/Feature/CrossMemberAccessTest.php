<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PayTrade;
use App\Services\Common\ConfigService;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/**
 * 越权检查：会员乙拿着自己的登录令牌，去碰会员甲的地址、购物车、订单、售后单、支付单、图片。
 * 每一个带 ID 或单号的买家接口都要过一遍；全部应该被拒绝，而且甲的数据不能有任何变化。
 */
class CrossMemberAccessTest extends FeatureTestCase
{
    private int $aId;

    private string $aToken;

    private string $bToken;

    private int $bId;

    private int $aAddress;

    private int $bAddress;

    private int $sku;

    protected function setUp(): void
    {
        parent::setUp();
        [$a, $this->aToken] = $this->makeMember();
        [$b, $this->bToken] = $this->makeMember();
        $this->aId = (int) $a->id;
        $this->bId = (int) $b->id;
        $this->aAddress = (int) $this->makeAddress($this->aId, ['name' => '甲的地址'])->id;
        $this->bAddress = (int) $this->makeAddress($this->bId, ['name' => '乙的地址'])->id;
        $this->sku = $this->makeSku('100.00', 50);
    }

    /** 乙发请求，断言被拒绝（不是成功） */
    private function denied(string $method, string $uri, array $data = []): void
    {
        $r = $this->api($method, $uri, $data, $this->bToken);
        $this->assertNotSame(0, $r->json('code'), "乙不应该能 {$method} {$uri}，却成功了：".$r->getContent());
        // 拒绝时也不能把甲的数据带出来
        $this->assertStringNotContainsString('甲的地址', $r->getContent());
    }

    public function test_addresses(): void
    {
        $before = (array) DB::table('member_address')->where('id', $this->aAddress)->first();

        $this->denied('GET', "/addresses/{$this->aAddress}");
        $this->denied('PUT', "/addresses/{$this->aAddress}", ['name' => '被改了', 'phone_area' => '86', 'phone' => '13900000000', 'country_code' => 'CN', 'province' => '北京市', 'city' => '北京市', 'address' => '乙填的地址']);
        $this->denied('PUT', "/addresses/{$this->aAddress}/default");
        $this->denied('DELETE', "/addresses/{$this->aAddress}");

        $this->assertSame($before, (array) DB::table('member_address')->where('id', $this->aAddress)->first());
        // 乙的地址列表里只有自己的
        $this->assertSame(['乙的地址'], array_column($this->api('GET', '/addresses', [], $this->bToken)->json('data'), 'name'));
    }

    public function test_cart(): void
    {
        $this->api('POST', '/cart', ['sku_id' => $this->sku, 'qty' => 2], $this->aToken)->assertJsonPath('code', 0);
        $cartId = (int) DB::table('cart')->where('member_id', $this->aId)->value('id');

        $this->denied('PUT', "/cart/{$cartId}", ['qty' => 9]);
        // 删除别人的购物车项：接口可以返回成功（什么都没删），关键是甲的东西还在
        $this->api('POST', '/cart/remove', ['ids' => [$cartId]], $this->bToken);
        // 用甲的购物车项结算、下单
        $this->denied('POST', '/orders/checkout', ['cart_ids' => [$cartId], 'address_id' => $this->bAddress]);
        $this->denied('POST', '/orders', ['cart_ids' => [$cartId], 'address_id' => $this->bAddress]);

        $this->assertSame(2, (int) DB::table('cart')->where('id', $cartId)->value('qty'));
        $this->assertSame([], $this->api('GET', '/cart', [], $this->bToken)->json('data.items') ?? []);
        $this->assertSame(0, Order::query()->where('member_id', $this->bId)->count());
    }

    public function test_ordering_with_someone_elses_address(): void
    {
        $this->denied('POST', '/orders/checkout', ['items' => [['sku_id' => $this->sku, 'qty' => 1]], 'address_id' => $this->aAddress]);
        $this->denied('POST', '/orders', ['items' => [['sku_id' => $this->sku, 'qty' => 1]], 'address_id' => $this->aAddress]);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_orders_payments_and_tracking(): void
    {
        $pending = $this->placeOrder($this->aToken, $this->sku, 1, $this->aAddress);
        $no = $pending->order_no;

        $this->denied('GET', "/orders/{$no}");
        $this->denied('POST', "/orders/{$no}/cancel", ['reason' => '乙来取消']);
        $this->denied('GET', "/orders/{$no}/pay/channels");
        $this->denied('POST', "/orders/{$no}/pay", ['channel' => 'stripe']);
        $this->assertSame(0, (int) $pending->refresh()->status, '甲的订单仍是待支付');
        $this->assertSame(0, PayTrade::query()->count());

        // 已发货的订单：不能替别人确认收货，不能看物流、不能申请售后
        $shipped = $this->pay($this->placeOrder($this->aToken, $this->sku, 2, $this->aAddress));
        $this->adminApi('POST', "/order/ship/{$shipped->id}", ['express_code' => 'SF', 'express_no' => 'SF-A-SECRET'])->assertJsonPath('code', 0);
        $sn = $shipped->order_no;
        $itemId = (int) DB::table('order_item')->where('order_id', $shipped->id)->value('id');

        $this->denied('POST', "/orders/{$sn}/receive");
        $this->denied('GET', "/orders/{$sn}/tracks");
        $this->denied('GET', "/orders/{$sn}/refundable");
        $this->denied('POST', "/orders/{$sn}/refunds", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '乙来申请']);
        $this->assertSame(2, (int) $shipped->refresh()->status, '甲的订单仍是待收货');
        $this->assertSame(0, OrderRefund::query()->count());

        // 乙自己有订单时，也不能拿甲订单里的商品行来申请售后
        $mine = $this->pay($this->placeOrder($this->bToken, $this->sku, 1, $this->bAddress));
        $this->denied('POST', "/orders/{$mine->order_no}/refunds", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '串单']);
        $this->assertSame(0, OrderRefund::query()->count());

        // 乙的订单列表里没有甲的
        $list = $this->api('GET', '/orders', [], $this->bToken);
        $this->assertSame([$mine->order_no], array_column($list->json('data.list'), 'order_no'));
        $this->assertStringNotContainsString('SF-A-SECRET', $list->getContent());
    }

    public function test_payment_confirmation(): void
    {
        $order = $this->placeOrder($this->aToken, $this->sku, 1, $this->aAddress);
        $trade = PayTrade::query()->create([
            'trade_no' => 'PTCROSS0001', 'order_id' => $order->id, 'member_id' => $this->aId, 'channel' => 'stripe',
            'channel_trade_no' => 'PTCROSS0001', 'trade_type' => 1, 'currency' => $order->currency, 'amount' => $order->pay_amount, 'status' => 0,
        ]);

        $this->denied('GET', "/pay/{$trade->trade_no}/confirm");
        $this->assertSame(0, (int) $trade->refresh()->status);
    }

    public function test_refunds(): void
    {
        $order = $this->pay($this->placeOrder($this->aToken, $this->sku, 1, $this->aAddress));
        $r = $this->api('POST', "/orders/{$order->order_no}/refunds", ['type' => 1, 'reason' => '甲自己申请'], $this->aToken);
        $this->assertSame(0, $r->json('code'), (string) $r->json('msg'));
        $refundNo = $r->json('data.refund_no');

        $this->denied('GET', "/refunds/{$refundNo}");
        $this->denied('POST', "/refunds/{$refundNo}/cancel");
        $this->denied('POST', "/refunds/{$refundNo}/return", ['express' => '顺丰', 'express_no' => 'SF-B']);

        $this->assertSame(0, (int) OrderRefund::query()->where('refund_no', $refundNo)->value('status'), '甲的售后单仍是待审核');
        $this->assertSame([], $this->api('GET', '/refunds', [], $this->bToken)->json('data.list'));
    }

    public function test_distribution_data_is_scoped_to_the_member(): void
    {
        app(ConfigService::class)->save('distribution', ['enabled' => 1, 'level' => 1, 'rate_1' => '10', 'min_withdraw' => '1']);
        // 甲是分销员，有佣金、提现记录和一个下级
        DB::table('distributor')->insert(['member_id' => $this->aId, 'real_name' => '甲的真名', 'status' => 1, 'available_amount' => '88.00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('member')->where('id', $this->bId)->update(['parent_id' => $this->aId]);

        foreach (['/distribution/summary', '/distribution/commissions', '/distribution/team', '/distribution/withdraws'] as $uri) {
            $body = $this->api('GET', $uri, [], $this->bToken)->getContent();
            $this->assertStringNotContainsString('甲的真名', $body, $uri);
            $this->assertStringNotContainsString('88.00', $body, $uri);
        }
        // 乙不是分销员，不能提现
        $this->denied('POST', '/distribution/withdraws', ['amount' => '10', 'account_name' => '乙', 'account_no' => '6222', 'bank' => '测试银行']);
        $this->assertSame('88.00', (string) DB::table('distributor')->where('member_id', $this->aId)->value('available_amount'));
    }

    public function test_points_and_profile_are_scoped_to_the_member(): void
    {
        DB::table('member_point_log')->insert(['member_id' => $this->aId, 'type' => 5, 'change_points' => 777, 'after_points' => 777, 'ref_no' => 'A-ONLY', 'remark' => '', 'admin_id' => 0, 'created_at' => now()]);

        $this->assertSame([], $this->api('GET', '/member/points', [], $this->bToken)->json('data.list'));
        // 传别人的 ID 也没用：接口只认令牌
        $p = $this->api('GET', '/member/profile?member_id='.$this->aId.'&id='.$this->aId, [], $this->bToken);
        $this->assertSame($this->bId, $p->json('data.id'));
        $this->api('PUT', '/member/profile', ['id' => $this->aId, 'member_id' => $this->aId, 'nickname' => '乙改的'], $this->bToken);
        $this->assertNotSame('乙改的', DB::table('member')->where('id', $this->aId)->value('nickname'));
    }
}
