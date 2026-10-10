<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Common\CouponService;
use App\Services\Common\DistributionService;
use App\Services\Common\OrderRefundService;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 搭配套餐：订单里凑齐一组商品就立减；和会员折扣、优惠券、积分、改价、分销、退款的关系 */
class BundleTest extends FeatureTestCase
{
    private int $memberId;

    private string $token;

    private int $addressId;

    /** 三个单规格商品：甲 100、乙 50、丙 30 */
    private int $a;

    private int $b;

    private int $c;

    protected function setUp(): void
    {
        parent::setUp();
        [$m, $this->token] = $this->makeMember();
        $this->memberId = (int) $m->id;
        $this->addressId = (int) $this->makeAddress($m->id)->id;
        $this->a = $this->makeSku('100.00', 50);
        $this->b = $this->makeSku('50.00', 50);
        $this->c = $this->makeSku('30.00', 50);
    }

    private function bundle(array $skuIds, string $reduce, array $over = []): int
    {
        return (int) $this->adminApi('POST', '/marketing/bundle', $over + ['name' => '测试套餐', 'reduce_amount' => $reduce, 'sku_ids' => $skuIds])
            ->assertJsonPath('code', 0)->json('data.id');
    }

    /** @param array<int,int> $cart 规格 ID => 数量 */
    private function items(array $cart): array
    {
        return array_map(fn ($sku, $qty) => ['sku_id' => $sku, 'qty' => $qty], array_keys($cart), $cart);
    }

    private function preview(array $cart, array $extra = [], array $headers = []): array
    {
        return (array) $this->api('POST', '/orders/checkout', ['items' => $this->items($cart), 'address_id' => $this->addressId] + $extra, $this->token, $headers)
            ->assertJsonPath('code', 0)->json('data');
    }

    private function order(array $cart, array $extra = [], array $headers = []): Order
    {
        $r = $this->api('POST', '/orders', ['items' => $this->items($cart), 'address_id' => $this->addressId] + $extra, $this->token, $headers);
        $this->assertSame(0, $r->json('code'), '下单失败：'.$r->json('msg'));

        return Order::query()->where('order_no', $r->json('data.order_no'))->firstOrFail();
    }

    private function goodsId(int $sku): int
    {
        return (int) DB::table('goods_sku')->where('id', $sku)->value('goods_id');
    }

    public function test_admin_manages_bundles_and_goods_page_shows_them(): void
    {
        $base = ['name' => '喂食器 + 滤芯', 'reduce_amount' => '20', 'sku_ids' => [$this->a, $this->b]];
        // 至少两件不同的规格；立减要小于售价合计；不存在的规格不行
        $this->adminApi('POST', '/marketing/bundle', ['sku_ids' => [$this->a]] + $base)->assertJsonPath('code', 422);
        $this->adminApi('POST', '/marketing/bundle', ['sku_ids' => [$this->a, $this->a]] + $base)->assertJsonPath('code', 1);
        $this->adminApi('POST', '/marketing/bundle', ['reduce_amount' => '150'] + $base)->assertJsonPath('msg', '立减金额必须小于这组商品的售价合计（150.00）');
        $this->adminApi('POST', '/marketing/bundle', ['sku_ids' => [$this->a, 99999999]] + $base)->assertJsonPath('msg', '选择的商品里有已下架或不存在的，请重新选择');
        $this->adminApi('POST', '/marketing/bundle', ['start_at' => '2026-10-02 00:00:00', 'end_at' => '2026-10-01 00:00:00'] + $base)->assertJsonPath('msg', '结束时间必须晚于开始时间');
        $this->assertSame(0, DB::table('bundle')->count());

        $id = $this->bundle([$this->a, $this->b], '20', ['name' => '喂食器 + 滤芯']);
        $row = $this->adminApi('GET', '/marketing/bundle/list')->json('data.list.0');
        $this->assertSame([$id, '150.00', '130.00', 'running', '', 2], [$row['id'], $row['total'], $row['bundle_price'], $row['period'], $row['problem'], count($row['items'])]);
        $this->assertContains($this->a, array_column($this->adminApi('GET', '/marketing/bundle/skus?keyword=测试商品')->json('data'), 'sku_id'));

        // 商品页：甲、乙都能看到这个套餐，丙没有；其他币种按汇率换算（1 元 = 0.14 美元）；名称不给买家
        $shown = $this->api('GET', '/goods/'.$this->goodsId($this->a))->json('data.bundles');
        $this->assertSame([[$id, '150.00', '20.00', '130.00']], array_map(fn ($b) => [$b['id'], $b['total'], $b['reduce'], $b['bundle_price']], $shown));
        $this->assertSame([$this->a, $this->b], array_column($shown[0]['items'], 'sku_id'));
        $this->assertArrayNotHasKey('name', $shown[0]);
        $this->assertCount(1, $this->api('GET', '/goods/'.$this->goodsId($this->b))->json('data.bundles'));
        $this->assertSame([], $this->api('GET', '/goods/'.$this->goodsId($this->c))->json('data.bundles'));
        $usd = $this->api('GET', '/goods/'.$this->goodsId($this->a), [], null, ['X-Currency' => 'USD'])->json('data.bundles.0');
        $this->assertSame(['21.00', '2.80', '18.20'], [$usd['total'], $usd['reduce'], $usd['bundle_price']]);

        // 套餐里有缺货的：商品页不显示，后台列表给出原因
        DB::table('goods_sku')->where('id', $this->b)->update(['stock' => 0]);
        $this->assertSame([], $this->api('GET', '/goods/'.$this->goodsId($this->a))->json('data.bundles'));
        $this->assertStringContainsString('缺货', $this->adminApi('GET', '/marketing/bundle/list')->json('data.list.0.problem'));
        DB::table('goods_sku')->where('id', $this->b)->update(['stock' => 50]);

        // 改、停用、删除
        $this->adminApi('PUT', "/marketing/bundle/{$id}", ['reduce_amount' => '25', 'sku_ids' => [$this->b, $this->c, $this->a]] + $base)->assertJsonPath('code', 0);
        $row = $this->adminApi('GET', '/marketing/bundle/list')->json('data.list.0');
        $this->assertSame(['180.00', '155.00', [$this->b, $this->c, $this->a]], [$row['total'], $row['bundle_price'], array_column($row['items'], 'sku_id')]);
        $this->adminApi('POST', "/marketing/bundle/{$id}/status", ['status' => 0])->assertJsonPath('code', 0);
        $this->assertSame([], $this->api('GET', '/goods/'.$this->goodsId($this->a))->json('data.bundles'));
        $this->adminApi('DELETE', "/marketing/bundle/{$id}")->assertJsonPath('code', 0);
        $this->assertSame(0, $this->adminApi('GET', '/marketing/bundle/list')->json('data.total'));
    }

    public function test_checkout_and_order_apply_bundle_per_complete_set(): void
    {
        $id = $this->bundle([$this->a, $this->b], '20');

        // 没凑齐不减
        $this->assertSame(['0.00', []], [$this->preview([$this->a => 2])['bundle_amount'], $this->preview([$this->a => 2])['bundles']]);
        // 甲 2 乙 1 丙 1：凑齐一套，减 20
        $p = $this->preview([$this->a => 2, $this->b => 1, $this->c => 1]);
        $this->assertSame(['280.00', '20.00', '260.00'], [$p['goods_amount'], $p['bundle_amount'], $p['pay_amount']]);
        $this->assertSame([['id' => $id, 'name' => '测试套餐', 'sets' => 1, 'amount' => '20.00']], $p['bundles']);
        // 甲 2 乙 3：两套
        $this->assertSame('40.00', $this->preview([$this->a => 2, $this->b => 3])['bundle_amount']);
        // 非人民币订单：立减 20 元按汇率换成 2.8 美元；下单后订单上记的也是美元
        $usd = $this->preview([$this->a => 1, $this->b => 1], [], ['X-Currency' => 'USD']);
        $this->assertSame(['21.00', '2.80', '18.20'], [$usd['goods_amount'], $usd['bundle_amount'], $usd['pay_amount']]);
        $usdOrder = $this->order([$this->a => 1, $this->b => 1], [], ['X-Currency' => 'USD']);
        $this->assertSame(['USD', '2.80', '18.20'], [$usdOrder->currency, (string) $usdOrder->bundle_amount, (string) $usdOrder->pay_amount]);
        // 分销计佣基数折回人民币：18.20 ÷ 0.14 = 130
        $this->assertSame('130.00', DistributionService::commissionBase($usdOrder));

        // 下单：金额和预览一致，订单上记下优惠金额和快照；买家和后台的订单详情都带出来
        $order = $this->order([$this->a => 2, $this->b => 1, $this->c => 1]);
        $this->assertSame(['280.00', '20.00', '260.00'], [(string) $order->goods_amount, (string) $order->bundle_amount, (string) $order->pay_amount]);
        $this->assertSame([['id' => $id, 'name' => '测试套餐', 'sets' => 1, 'amount' => '20.00']], json_decode((string) $order->bundle_info, true));
        $this->assertSame('20.00', $this->api('GET', "/orders/{$order->order_no}", [], $this->token)->json('data.bundle_amount'));
        $detail = $this->adminApi('GET', "/order/list/{$order->id}")->json('data');
        $this->assertSame(['20.00', 1], [$detail['bundle_amount'], $detail['bundles'][0]['sets']]);

        // 后台改价：上限是“商品 + 运费 − 套餐优惠”，改完恒等式还成立
        $this->adminApi('POST', "/order/list/{$order->id}/price", ['pay_amount' => '260.01'])->assertJsonPath('code', 1);
        $this->adminApi('POST', "/order/list/{$order->id}/price", ['pay_amount' => '250'])->assertJsonPath('code', 0);
        $order->refresh();
        $this->assertSame('250.00', bcsub(bcsub(bcadd((string) $order->goods_amount, (string) $order->shipping_fee, 2), (string) $order->bundle_amount, 2), (string) $order->discount_amount, 2));

        // 停用、还没开始、已经结束的套餐都不生效；之前的订单不受影响
        $this->adminApi('POST', "/marketing/bundle/{$id}/status", ['status' => 0]);
        $this->assertSame('0.00', $this->preview([$this->a => 1, $this->b => 1])['bundle_amount']);
        DB::table('bundle')->where('id', $id)->update(['status' => 1, 'start_at' => now()->addDay()]);
        $this->assertSame('0.00', $this->preview([$this->a => 1, $this->b => 1])['bundle_amount']);
        DB::table('bundle')->where('id', $id)->update(['start_at' => now()->subDays(2), 'end_at' => now()->subDay()]);
        $this->assertSame('0.00', $this->preview([$this->a => 1, $this->b => 1])['bundle_amount']);
        DB::table('bundle')->where('id', $id)->update(['end_at' => now()->addDay()]);
        $this->assertSame('20.00', $this->preview([$this->a => 1, $this->b => 1])['bundle_amount']);
        $this->assertSame('20.00', (string) $order->refresh()->bundle_amount);
    }

    public function test_overlapping_bundles_use_each_item_once_and_prefer_the_bigger_reduction(): void
    {
        $small = $this->bundle([$this->a, $this->b], '20');
        $big = $this->bundle([$this->a, $this->c], '25');

        // 甲只有 1 件：只能进一个套餐，进减得多的那个
        $p = $this->preview([$this->a => 1, $this->b => 1, $this->c => 1]);
        $this->assertSame(['25.00', [$big]], [$p['bundle_amount'], array_column($p['bundles'], 'id')]);
        // 甲有 2 件：两个套餐各凑一套
        $p = $this->preview([$this->a => 2, $this->b => 1, $this->c => 1]);
        $this->assertSame(['45.00', [$big, $small]], [$p['bundle_amount'], array_column($p['bundles'], 'id')]);
    }

    public function test_member_discount_coupon_and_points_are_calculated_after_the_bundle(): void
    {
        $this->bundle([$this->a, $this->b], '20');
        DB::table('member_level')->updateOrInsert(['id' => 1], ['level_no' => 1, 'need_points' => 0, 'discount' => 90, 'status' => 1]);

        // 会员九折：按套餐优惠后的 130 打折，不是按 150
        $p = $this->preview([$this->a => 1, $this->b => 1]);
        $this->assertSame(['20.00', '13.00', '117.00'], [$p['bundle_amount'], $p['discount_amount'], $p['pay_amount']]);
        DB::table('member_level')->where('id', 1)->update(['discount' => 100]);

        // 优惠券满 140 减 30：商品原价 150 够，但套餐优惠后只有 130，不够门槛
        $coupon = Coupon::query()->create([
            'name' => '满 140 减 30', 'type' => 1, 'threshold' => '140', 'amount' => '30', 'discount' => 0, 'max_amount' => 0, 'scope' => 1, 'scope_ids' => [],
            'total' => 0, 'received' => 0, 'per_member' => 1, 'valid_type' => 1, 'valid_start' => now()->subDay(), 'valid_end' => now()->addDays(30), 'is_public' => 1, 'status' => 1,
        ]);
        $mc = (int) DB::transaction(fn () => app(CouponService::class)->issue($coupon, $this->memberId, false))->id;
        $p = $this->preview([$this->a => 1, $this->b => 1]);
        $this->assertSame([false, 0], [$p['coupons'][0]['usable'], $p['member_coupon_id']]);
        $this->api('POST', '/orders', ['items' => $this->items([$this->a => 1, $this->b => 1]), 'address_id' => $this->addressId, 'member_coupon_id' => $mc], $this->token)->assertJsonPath('code', 1);

        // 再加一件丙（套餐后 160）就能用了：套餐和优惠券叠加
        $p = $this->preview([$this->a => 1, $this->b => 1, $this->c => 1]);
        $this->assertSame(['180.00', '20.00', '30.00', '130.00', $mc], [$p['goods_amount'], $p['bundle_amount'], $p['coupon_amount'], $p['pay_amount'], $p['member_coupon_id']]);
        $order = $this->order([$this->a => 1, $this->b => 1, $this->c => 1], ['member_coupon_id' => $mc]);
        $this->assertSame(['20.00', '30.00', '130.00'], [(string) $order->bundle_amount, (string) $order->coupon_amount, (string) $order->pay_amount]);

        // 分销计佣基数扣掉套餐优惠和优惠券
        $this->assertSame('130.00', DistributionService::commissionBase($order));
    }

    public function test_refunding_one_item_of_a_bundle_returns_its_share_of_what_was_paid(): void
    {
        $this->bundle([$this->a, $this->b], '20');
        $order = $this->pay($this->order([$this->a => 1, $this->b => 1]));
        $this->assertSame('130.00', (string) $order->pay_amount);

        // 退掉甲（100 / 150 的份额）：退 86.67，不追回套餐优惠；订单变成部分退款
        $itemA = (int) DB::table('order_item')->where('order_id', $order->id)->where('sku_id', $this->a)->value('id');
        $info = $this->api('GET', "/orders/{$order->order_no}/refundable", [], $this->token)->json('data');
        $this->assertSame('130.00', $info['whole_order']['amount']);
        $no = $this->api('POST', "/orders/{$order->order_no}/refunds", ['order_item_id' => $itemA, 'qty' => 1, 'type' => 1, 'reason' => '只要滤芯'], $this->token)
            ->assertJsonPath('code', 0)->json('data.refund_no');
        $refund = OrderRefund::query()->where('refund_no', $no)->firstOrFail();
        $this->assertSame('86.67', (string) $refund->refund_amount);
        app(OrderRefundService::class)->approve((int) $refund->id, 1);
        app(OrderRefundService::class)->pay((int) $refund->id, 1, true);
        $this->assertSame('20.00', (string) $order->refresh()->bundle_amount);
    }
}
