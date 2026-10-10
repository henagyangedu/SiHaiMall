<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\MemberCoupon;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Common\ConfigService;
use App\Services\Common\CouponService;
use App\Services\Common\DistributionService;
use App\Services\Common\OrderRefundService;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 买家领券、结算预览选券、下单用券、取消退券，以及用券后佣金和退款的金额 */
class CouponUseTest extends FeatureTestCase
{
    private int $memberId;

    private string $token;

    private int $addressId;

    private int $sku;

    private int $goodsId;

    protected function setUp(): void
    {
        parent::setUp();
        [$m, $this->token] = $this->makeMember();
        $this->memberId = (int) $m->id;
        $this->addressId = (int) $this->makeAddress($m->id)->id;
        $this->sku = $this->makeSku('100.00', 100);
        $this->goodsId = (int) DB::table('goods_sku')->where('id', $this->sku)->value('goods_id');
    }

    private function coupon(array $over = []): Coupon
    {
        return Coupon::query()->create($over + [
            'name' => '满 300 减 30', 'type' => 1, 'threshold' => '300', 'amount' => '30', 'discount' => 0, 'max_amount' => 0, 'scope' => 1, 'scope_ids' => [],
            'total' => 0, 'received' => 0, 'per_member' => 1, 'valid_type' => 1, 'valid_start' => now()->subDay(), 'valid_end' => now()->addDays(30),
            'is_public' => 1, 'status' => 1,
        ]);
    }

    /** 让当前会员手里有一张这种券，返回会员券 ID */
    private function give(Coupon $c, ?int $memberId = null): int
    {
        return (int) DB::transaction(fn () => app(CouponService::class)->issue($c->refresh(), $memberId ?? $this->memberId, false))->id;
    }

    private function preview(int $qty, array $extra = [], array $headers = [])
    {
        return $this->api('POST', '/orders/checkout', ['items' => [['sku_id' => $this->sku, 'qty' => $qty]], 'address_id' => $this->addressId] + $extra, $this->token, $headers);
    }

    public function test_buyers_claim_coupons_within_the_limits(): void
    {
        $open = $this->coupon(['per_member' => 2, 'total' => 3]);
        $hidden = $this->coupon(['name' => '只能后台发', 'is_public' => 0]);
        $soon = $this->coupon(['name' => '还没开始领', 'receive_start' => now()->addDay()]);
        $stopped = $this->coupon(['name' => '停用的', 'status' => 0]);
        $ended = $this->coupon(['name' => '过期的', 'valid_end' => now()->subMinute()]);

        // 领券中心：不用登录；只列出现在真能领的
        $list = $this->api('GET', '/coupons');
        $list->assertJsonPath('code', 0);
        $this->assertSame([$open->id], array_column($list->json('data'), 'coupon_id'));
        $this->assertSame([0, true], [$list->json('data.0.received'), $list->json('data.0.can_receive')]);

        $this->api('POST', "/coupons/{$open->id}/receive")->assertJsonPath('code', 401);
        $this->api('POST', "/coupons/{$open->id}/receive", [], $this->token)->assertJsonPath('code', 0)->assertJsonPath('msg', '领取成功');
        $this->api('POST', "/coupons/{$open->id}/receive", [], $this->token)->assertJsonPath('code', 0);
        // 每人限领 2 张
        $this->api('POST', "/coupons/{$open->id}/receive", [], $this->token)->assertJsonPath('code', 1)->assertJsonPath('msg', '已经领过了');
        $mine = $this->api('GET', '/coupons', [], $this->token)->json('data.0');
        $this->assertSame([2, false], [$mine['received'], $mine['can_receive']]);

        // 发行 3 张，别人领走最后一张后就领完了，领券中心也不再显示
        [, $other] = $this->makeMember();
        $this->api('POST', "/coupons/{$open->id}/receive", [], $other)->assertJsonPath('code', 0);
        [, $late] = $this->makeMember();
        $this->api('POST', "/coupons/{$open->id}/receive", [], $late)->assertJsonPath('msg', '优惠券已经领完了');
        $this->assertSame([], $this->api('GET', '/coupons')->json('data'));
        $this->assertSame(3, (int) $open->refresh()->received);

        // 不公开、没到时间、停用、过期、不存在的都领不了
        foreach ([[$hidden->id, '这张优惠券不能领取'], [$soon->id, '不在领取时间内'], [$stopped->id, '这张优惠券已停用'], [$ended->id, '这张优惠券已过期'], [999999, '优惠券不存在']] as [$id, $msg]) {
            $this->api('POST', "/coupons/{$id}/receive", [], $this->token)->assertJsonPath('code', 1)->assertJsonPath('msg', $msg);
        }
        $this->assertSame('You have already claimed this coupon', $this->api('POST', "/coupons/{$open->id}/receive", [], $this->token, ['X-Lang' => 'en'])->json('msg'));

        // 我的优惠券：按状态分；别人看不到我的
        $this->assertSame(2, $this->api('GET', '/member/coupons', [], $this->token)->json('data.total'));
        $this->assertSame(0, $this->api('GET', '/member/coupons?status=used', [], $this->token)->json('data.total'));
        $this->assertSame(0, $this->api('GET', '/member/coupons', [], $late)->json('data.total'));
        MemberCoupon::query()->where('member_id', $this->memberId)->limit(1)->update(['valid_end' => now()->subMinute()]);
        $this->assertSame([1, 1], [$this->api('GET', '/member/coupons', [], $this->token)->json('data.total'), $this->api('GET', '/member/coupons?status=expired', [], $this->token)->json('data.total')]);
    }

    public function test_checkout_preview_picks_the_best_coupon_and_lets_the_buyer_choose(): void
    {
        $small = $this->give($this->coupon(['name' => '满 100 减 5', 'threshold' => '100', 'amount' => '5']));
        $big = $this->give($this->coupon());
        $tenOff = $this->give($this->coupon(['name' => '9 折，最多减 25', 'type' => 2, 'threshold' => '200', 'amount' => 0, 'discount' => '90', 'max_amount' => '25']));

        // 买 1 件（100 元）：只有“满 100 减 5”能用，其余两张说明还差多少
        $p = $this->preview(1);
        $p->assertJsonPath('code', 0);
        $this->assertSame([$small, '5.00', '95.00'], [$p->json('data.member_coupon_id'), $p->json('data.coupon_amount'), $p->json('data.pay_amount')]);
        $rows = collect($p->json('data.coupons'))->keyBy('id');
        $this->assertSame([true, false, false], [$rows[$small]['usable'], $rows[$big]['usable'], $rows[$tenOff]['usable']]);
        $this->assertSame('适用商品还差 200 元可用', $rows[$big]['reason']);
        $this->assertSame('30.00', $rows[$big]['face_amount'], '不能用的券也要显示面额');

        // 买 3 件（300 元）：三张都能用，自动选减得最多的（满 300 减 30 > 9 折封顶 25 > 减 5）
        $p = $this->preview(3);
        $this->assertSame([$big, '30.00', '270.00'], [$p->json('data.member_coupon_id'), $p->json('data.coupon_amount'), $p->json('data.pay_amount')]);
        $this->assertSame([$big, $tenOff, $small], array_column($p->json('data.coupons'), 'id'));
        $this->assertSame('25.00', collect($p->json('data.coupons'))->firstWhere('id', $tenOff)['amount']);

        // 买家可以换一张，或者不用
        $this->assertSame(['25.00', '275.00'], [$this->preview(3, ['member_coupon_id' => $tenOff])->json('data.coupon_amount'), $this->preview(3, ['member_coupon_id' => $tenOff])->json('data.pay_amount')]);
        $none = $this->preview(3, ['member_coupon_id' => 0]);
        $this->assertSame([0, '0.00', '300.00'], [$none->json('data.member_coupon_id'), $none->json('data.coupon_amount'), $none->json('data.pay_amount')]);
        // 指定了一张这单用不了的：不用券，并在 problems 里说明
        $bad = $this->preview(1, ['member_coupon_id' => $big]);
        $this->assertSame('0.00', $bad->json('data.coupon_amount'));
        $this->assertStringContainsString('优惠券', json_encode($bad->json('data.problems'), JSON_UNESCAPED_UNICODE));

        // 非人民币订单：券面金额按汇率换算（1 元 = 0.14 美元）。300 元的货是 42 美元，“满 300 减 30”变成“满 42 减 4.2”
        $usd = $this->preview(3, [], ['X-Currency' => 'USD']);
        $this->assertSame([$big, '42.00', '4.20', '37.80'], [$usd->json('data.member_coupon_id'), $usd->json('data.goods_amount'), $usd->json('data.coupon_amount'), $usd->json('data.pay_amount')]);
        $rows = collect($usd->json('data.coupons'))->keyBy('id');
        $this->assertSame(['USD', '42.00', '4.20', '4.20'], [$rows[$big]['currency'], $rows[$big]['threshold'], $rows[$big]['face_amount'], $rows[$big]['amount']]);
        $this->assertSame(['3.50', '3.50'], [$rows[$tenOff]['amount'], $rows[$tenOff]['max_amount']], '折扣券的封顶 25 元也换算成 3.5 美元');
        $this->assertSame('0.70', $rows[$small]['amount']);
        // 买 1 件（14 美元）够不上门槛：提示里的差额也是美元
        $one = collect($this->preview(1, [], ['X-Currency' => 'USD'])->json('data.coupons'))->keyBy('id');
        $this->assertSame([false, '适用商品还差 28 USD 可用'], [$one[$big]['usable'], $one[$big]['reason']]);
        $this->assertSame('Add USD 28 more of eligible items to use this coupon', collect($this->preview(1, [], ['X-Currency' => 'USD', 'X-Lang' => 'en'])->json('data.coupons'))->keyBy('id')[$big]['reason']);
        // 人民币下看到的还是人民币的券面
        $this->assertSame(['CNY', '300.00'], [collect($this->preview(3)->json('data.coupons'))->keyBy('id')[$big]['currency'], collect($this->preview(3)->json('data.coupons'))->keyBy('id')[$big]['threshold']]);

        // 美元下单：订单上记的优惠券金额是美元；领券中心和“我的优惠券”也按美元显示
        $order = $this->placeOrder($this->token, $this->sku, 3, $this->addressId, ['member_coupon_id' => $big], ['X-Currency' => 'USD']);
        $this->assertSame(['USD', '42.00', '4.20', '37.80'], [$order->currency, (string) $order->goods_amount, (string) $order->coupon_amount, (string) $order->pay_amount]);
        $mine = collect($this->api('GET', '/member/coupons', [], $this->token, ['X-Currency' => 'USD'])->json('data.list'))->keyBy('id');
        $this->assertSame(['USD', '14.00', '0.70'], [$mine[$small]['currency'], $mine[$small]['threshold'], $mine[$small]['face_amount']]);
        $this->assertSame('USD', $this->api('GET', '/coupons', [], null, ['X-Currency' => 'USD'])->json('data.0.currency'));
        $this->assertSame('CNY', $this->api('GET', '/coupons')->json('data.0.currency'));
    }

    public function test_scope_member_discount_and_points_interact_in_the_documented_order(): void
    {
        // 9 折会员；另一个分类里的商品
        DB::table('member_level')->updateOrInsert(['id' => 1], ['level_no' => 1, 'need_points' => 0, 'discount' => 90, 'status' => 1]);
        $otherSku = $this->makeSku('200.00', 50);
        $category = (int) DB::table('goods')->where('id', $this->goodsId)->value('category_id');
        $child = (int) DB::table('category')->insertGetId(['pid' => $category, 'status' => 1]);
        $childSku = $this->makeSku('50.00', 50, ['category_id' => $child]);

        // 只适用于第一个商品所在分类（含下级分类）的 8 折券
        $id = $this->give($this->coupon(['name' => '分类 8 折', 'type' => 2, 'threshold' => '100', 'amount' => 0, 'discount' => '80', 'scope' => 2, 'scope_ids' => [$category]]));
        $items = [['sku_id' => $this->sku, 'qty' => 1], ['sku_id' => $otherSku, 'qty' => 1], ['sku_id' => $childSku, 'qty' => 1]];
        $p = $this->api('POST', '/orders/checkout', ['items' => $items, 'address_id' => $this->addressId], $this->token);
        // 商品 350，会员折扣 35；适用商品 100 + 50 = 150，扣掉会员折扣后 135，打 8 折减 27
        $this->assertSame(['350.00', '35.00', '27.00', '288.00'], [$p->json('data.goods_amount'), $p->json('data.discount_amount'), $p->json('data.coupon_amount'), $p->json('data.pay_amount')]);

        // 再用积分：积分抵扣的上限按“扣完会员折扣和优惠券之后”的金额算
        app(ConfigService::class)->save('trade', ['points_deduct_enabled' => 1, 'points_per_unit' => 10, 'points_max_percent' => 50]);
        DB::table('member')->where('id', $this->memberId)->update(['points' => 100000]);
        $p = $this->api('POST', '/orders/checkout', ['items' => $items, 'address_id' => $this->addressId, 'use_points' => true], $this->token);
        $this->assertSame(['27.00', '144.00', '144.00'], [$p->json('data.coupon_amount'), $p->json('data.points_amount'), $p->json('data.pay_amount')]);

        // 只买不适用的商品：这张券用不上
        $only = $this->api('POST', '/orders/checkout', ['items' => [['sku_id' => $otherSku, 'qty' => 1]], 'address_id' => $this->addressId], $this->token);
        $this->assertSame(['0.00', '订单里没有这张券适用的商品'], [$only->json('data.coupon_amount'), collect($only->json('data.coupons'))->firstWhere('usable', false)['reason'] ?? null]);

        // 下单结果和预览一致，恒等式成立
        $r = $this->api('POST', '/orders', ['items' => $items, 'address_id' => $this->addressId, 'use_points' => true, 'member_coupon_id' => $id], $this->token);
        $this->assertSame(0, $r->json('code'), (string) $r->json('msg'));
        $o = Order::query()->where('order_no', $r->json('data.order_no'))->firstOrFail();
        $this->assertSame(['27.00', '144.00', '144.00', $id], [(string) $o->coupon_amount, (string) $o->points_amount, (string) $o->pay_amount, (int) $o->member_coupon_id]);
        $this->assertSame((string) $o->pay_amount, bcsub(bcsub(bcsub(bcadd((string) $o->goods_amount, (string) $o->shipping_fee, 2), (string) $o->discount_amount, 2), (string) $o->coupon_amount, 2), (string) $o->points_amount, 2));
    }

    public function test_a_coupon_never_makes_the_goods_free(): void
    {
        $id = $this->give($this->coupon(['name' => '无门槛减 500', 'threshold' => '0', 'amount' => '500']));

        $p = $this->preview(1);
        $this->assertSame(['99.99', '0.01'], [$p->json('data.coupon_amount'), $p->json('data.pay_amount')]);
        $order = $this->placeOrder($this->token, $this->sku, 1, $this->addressId, ['member_coupon_id' => $id]);
        $this->assertSame(['99.99', '0.01'], [(string) $order->coupon_amount, (string) $order->pay_amount]);
    }

    public function test_placing_an_order_uses_the_coupon_and_cancelling_gives_it_back(): void
    {
        $id = $this->give($this->coupon());
        [$other, $otherToken] = $this->makeMember();
        $otherAddr = (int) $this->makeAddress($other->id)->id;

        // 别人不能用我的券；这单用不了的券下单会被拒绝，而不是悄悄不用
        $this->api('POST', '/orders', ['items' => [['sku_id' => $this->sku, 'qty' => 3]], 'address_id' => $otherAddr, 'member_coupon_id' => $id], $otherToken)
            ->assertJsonPath('code', 1)->assertJsonPath('msg', '优惠券不存在');
        $r = $this->api('POST', '/orders', ['items' => [['sku_id' => $this->sku, 'qty' => 1]], 'address_id' => $this->addressId, 'member_coupon_id' => $id], $this->token);
        $r->assertJsonPath('code', 1);
        $this->assertStringContainsString('不能用于本单', (string) $r->json('msg'));
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(100, (int) DB::table('goods_sku')->where('id', $this->sku)->value('stock'), '被拒绝的下单不占库存');

        // 不传券就不用券（下单不会自动选）
        $plain = $this->placeOrder($this->token, $this->sku, 3, $this->addressId);
        $this->assertSame(['0.00', '300.00'], [(string) $plain->coupon_amount, (string) $plain->pay_amount]);

        $order = $this->placeOrder($this->token, $this->sku, 3, $this->addressId, ['member_coupon_id' => $id]);
        $this->assertSame(['30.00', '270.00'], [(string) $order->coupon_amount, (string) $order->pay_amount]);
        $mc = MemberCoupon::query()->findOrFail($id);
        $this->assertSame([1, (int) $order->id], [(int) $mc->status, (int) $mc->order_id]);
        $this->assertNotNull($mc->used_at);
        $this->api('GET', "/orders/{$order->order_no}", [], $this->token)->assertJsonPath('data.coupon_amount', '30.00');
        $this->assertSame(1, $this->api('GET', '/member/coupons?status=used', [], $this->token)->json('data.total'));

        // 用过的券不能再用
        $this->api('POST', '/orders', ['items' => [['sku_id' => $this->sku, 'qty' => 3]], 'address_id' => $this->addressId, 'member_coupon_id' => $id], $this->token)
            ->assertJsonPath('msg', '这张优惠券已经用过了');
        $this->assertSame(0, $this->preview(3)->json('data.member_coupon_id'));

        // 取消订单：券退回，可以再用；重复取消不会出问题
        $this->api('POST', "/orders/{$order->order_no}/cancel", [], $this->token)->assertJsonPath('code', 0);
        $mc->refresh();
        $this->assertSame([0, 0, null], [(int) $mc->status, (int) $mc->order_id, $mc->used_at]);
        $this->assertSame($id, $this->preview(3)->json('data.member_coupon_id'));
        $again = $this->placeOrder($this->token, $this->sku, 3, $this->addressId, ['member_coupon_id' => $id]);

        // 超时未付款关闭：同样退回
        Order::query()->whereKey($again->id)->update(['expire_at' => now()->subMinute()]);
        $this->artisan('mall:close-expired-orders')->assertSuccessful();
        $this->assertSame(0, (int) MemberCoupon::query()->find($id)->status);
    }

    public function test_paid_orders_keep_the_coupon_used_and_refunds_and_commission_follow_the_discounted_amount(): void
    {
        app(ConfigService::class)->save('distribution', ['enabled' => 1, 'level' => 1, 'rate_1' => '10']);
        [$parent] = $this->makeMember();
        DB::table('distributor')->insert(['member_id' => $parent->id, 'real_name' => '上级', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('member')->where('id', $this->memberId)->update(['parent_id' => $parent->id]);

        $id = $this->give($this->coupon());
        $order = $this->pay($this->placeOrder($this->token, $this->sku, 3, $this->addressId, ['member_coupon_id' => $id]));

        // 佣金按券后的金额算：(300 - 30) × 10%
        $this->assertSame('270.00', DistributionService::commissionBase($order->refresh()));
        $this->assertSame('27.00', (string) DB::table('distribution_commission')->where('order_id', $order->id)->value('amount'));

        // 可退金额按券后的实付算：整单 270（3 件商品这一行也是 270），不是 300
        $itemId = (int) DB::table('order_item')->where('order_id', $order->id)->value('id');
        $info = $this->api('GET', "/orders/{$order->order_no}/refundable", [], $this->token)->json('data');
        $this->assertSame('270.00', (string) $info['whole_order']['amount']);
        $this->assertSame('270.00', (string) collect($info['items'])->firstWhere('item_id', $itemId)['max_amount']);

        // 退 1 件：按券后实付分摊，退 90 而不是 100。已付款的订单退款不退券
        $r = $this->api('POST', "/orders/{$order->order_no}/refunds", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '不要了'], $this->token);
        $this->assertSame(0, $r->json('code'), (string) $r->json('msg'));
        $refund = OrderRefund::query()->where('refund_no', $r->json('data.refund_no'))->firstOrFail();
        $this->assertSame('90.00', (string) $refund->refund_amount);
        app(OrderRefundService::class)->approve((int) $refund->id, 1, null);
        app(OrderRefundService::class)->pay((int) $refund->id, 1, true);
        $this->assertSame(1, (int) MemberCoupon::query()->find($id)->status, '已付款订单退款不退券');
    }

    public function test_admin_sees_the_coupon_on_the_order_and_price_changes_keep_the_books_balanced(): void
    {
        $id = $this->give($this->coupon());
        $order = $this->placeOrder($this->token, $this->sku, 3, $this->addressId, ['member_coupon_id' => $id]);

        $this->adminApi('GET', "/order/list/{$order->id}")->assertJsonPath('data.coupon_amount', '30.00');
        // 后台改价后，恒等式仍然成立
        $this->adminApi('POST', "/order/list/{$order->id}/price", ['pay_amount' => '250.00', 'remark' => '测试改价'])->assertJsonPath('code', 0);
        $o = $order->refresh();
        $this->assertSame('250.00', (string) $o->pay_amount);
        $this->assertSame('250.00', bcsub(bcsub(bcsub(bcadd((string) $o->goods_amount, (string) $o->shipping_fee, 2), (string) $o->discount_amount, 2), (string) $o->coupon_amount, 2), (string) $o->points_amount, 2));
        // 记录里能看到用在了哪张订单
        $coupon = (int) MemberCoupon::query()->find($id)->coupon_id;
        $this->adminApi('GET', "/marketing/coupon/{$coupon}/records")->assertJsonPath('data.list.0.order_no', $order->order_no)->assertJsonPath('data.list.0.status_text', '已使用');
    }
}
