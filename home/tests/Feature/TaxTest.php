<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Common\ConfigService;
use App\Services\Common\OrderStatusService;
use App\Services\Common\TaxService;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 税费：按收货地址匹配税率、价外税和价内税、运费计税，以及退款、积分、改价跟着税走 */
class TaxTest extends FeatureTestCase
{
    private string $token;

    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();
        [$member, $this->token] = $this->makeMember();
        $this->memberId = (int) $member->id;
        app(ConfigService::class)->save('trade', ['tax_enabled' => 1]);
    }

    private function rule(array $d): int
    {
        $this->adminApi('POST', '/tax-rate', $d + ['name' => 'Sales Tax', 'included' => 0, 'shipping_taxable' => 0, 'status' => 1])->assertJsonPath('code', 0);

        return (int) DB::table('tax_rate')->max('id');
    }

    private function address(string $country, string $province = ''): int
    {
        return (int) $this->makeAddress($this->memberId, ['country_code' => $country, 'province' => $province, 'city' => $province === '' ? '' : 'City'])->id;
    }

    /** 运费模板：全球首件 10、续件 5 */
    private function shippedSku(string $price): int
    {
        $tpl = DB::table('shipping_template')->insertGetId(['name' => '测试模板', 'charge_type' => 1, 'is_free_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipping_template_region')->insert(['template_id' => $tpl, 'countries' => '*', 'provinces' => '', 'first_unit' => 1, 'first_fee' => 10, 'step_unit' => 1, 'step_fee' => 5, 'free_amount' => 0]);

        return $this->makeSku($price, 20, ['shipping_template_id' => $tpl]);
    }

    private function preview(int $sku, int $qty, int $addressId): array
    {
        $r = $this->api('POST', '/orders/checkout', ['items' => [['sku_id' => $sku, 'qty' => $qty]], 'address_id' => $addressId], $this->token);
        $r->assertJsonPath('code', 0);

        return $r->json('data');
    }

    public function test_pure_calculation(): void
    {
        // 价外：100 × 7.25% = 7.25；运费 10 也计税 0.73（四舍五入）
        $this->assertSame(['amount' => '7.98', 'shipping' => '0.73', 'rate' => '7.250', 'included' => 0, 'extra' => '7.98'], TaxService::calc('100.00', '10.00', '7.25', false, true, 2));
        // 价内：120 里含 20，不另加
        $this->assertSame(['amount' => '20.00', 'shipping' => '0.00', 'rate' => '20.000', 'included' => 1, 'extra' => '0.00'], TaxService::calc('120.00', '10.00', '20', true, false, 2));
        // 没有小数的币种取整
        $this->assertSame('73.00', TaxService::part('1000', '7.25', false, 0));
        $this->assertSame('0.00', TaxService::part('0.00', '7.25', false, 2));
    }

    public function test_rule_matching_and_switch(): void
    {
        $this->rule(['country_code' => 'US', 'province' => '', 'rate' => '5']);
        $this->rule(['country_code' => 'us', 'province' => 'California', 'rate' => '7.25']);
        $off = $this->rule(['country_code' => 'DE', 'province' => '', 'rate' => '19', 'name' => 'MwSt.', 'included' => 1]);
        $sku = $this->makeSku('100.00', 20);

        // 州的规则优先（不分大小写），其他州用整个国家的；没有规则的国家不收税
        $ca = $this->preview($sku, 1, $this->address('US', 'california'));
        $this->assertSame(['7.25', 'Sales Tax', '7.25', 0, '107.25'], [$ca['tax_amount'], $ca['tax_name'], $ca['tax_rate'], $ca['tax_included'], $ca['pay_amount']]);
        $tx = $this->preview($sku, 1, $this->address('US', 'Texas'));
        $this->assertSame(['5.00', '5', '105.00'], [$tx['tax_amount'], $tx['tax_rate'], $tx['pay_amount']]);
        $jp = $this->preview($sku, 1, $this->address('JP'));
        $this->assertSame(['0.00', '', '100.00'], [$jp['tax_amount'], $jp['tax_name'], $jp['pay_amount']]);

        // 价内税：应付不变，标出其中含多少。100 × 19 ÷ 119 = 15.97
        $de = $this->address('DE');
        $p = $this->preview($sku, 1, $de);
        $this->assertSame(['15.97', 'MwSt.', 1, '100.00'], [$p['tax_amount'], $p['tax_name'], $p['tax_included'], $p['pay_amount']]);

        // 停用规则、关掉总开关，都不再收税
        $this->adminApi('PUT', "/tax-rate/{$off}", ['country_code' => 'DE', 'province' => '', 'name' => 'MwSt.', 'rate' => '19', 'included' => 1, 'status' => 0])->assertJsonPath('code', 0);
        $this->assertSame('0.00', $this->preview($sku, 1, $de)['tax_amount']);
        app(ConfigService::class)->save('trade', ['tax_enabled' => 0]);
        $this->assertSame('100.00', $this->preview($sku, 1, $this->address('US', 'California'))['pay_amount']);
    }

    public function test_admin_rules_are_validated(): void
    {
        $id = $this->rule(['country_code' => 'US', 'province' => 'California', 'rate' => '7.25']);
        $list = $this->adminApi('GET', '/tax-rate/list')->json('data');
        $this->assertSame([true, 'US', 'California', '7.25'], [$list['enabled'], $list['list'][0]['country_code'], $list['list'][0]['province'], $list['list'][0]['rate']]);

        $base = ['name' => 'Tax', 'included' => 0, 'shipping_taxable' => 0, 'status' => 1];
        // 同一个地区只能有一条（州名不分大小写）；税率要是 0 到 100 之间的数；国内省份要用标准名称
        $this->assertSame('这个地区已经有一条税率规则了', $this->adminApi('POST', '/tax-rate', $base + ['country_code' => 'US', 'province' => 'CALIFORNIA', 'rate' => '8'])->json('msg'));
        $this->assertNotSame(0, $this->adminApi('POST', '/tax-rate', $base + ['country_code' => 'US', 'province' => '', 'rate' => '0'])->json('code'));
        $this->assertNotSame(0, $this->adminApi('POST', '/tax-rate', $base + ['country_code' => 'US', 'province' => '', 'rate' => '100'])->json('code'));
        $this->assertNotSame(0, $this->adminApi('POST', '/tax-rate', $base + ['country_code' => 'USA', 'province' => '', 'rate' => '5'])->json('code'));
        $this->assertSame('省份名称不在标准名单里：广东', $this->adminApi('POST', '/tax-rate', $base + ['country_code' => 'CN', 'province' => '广东', 'rate' => '13'])->json('msg'));
        $this->adminApi('POST', '/tax-rate', $base + ['country_code' => 'CN', 'province' => '广东省', 'rate' => '13'])->assertJsonPath('code', 0);

        // 改自己不算重复；删除
        $this->adminApi('PUT', "/tax-rate/{$id}", $base + ['country_code' => 'US', 'province' => 'California', 'rate' => '7.5'])->assertJsonPath('code', 0);
        $this->adminApi('DELETE', "/tax-rate/{$id}")->assertJsonPath('code', 0);
        $this->assertCount(1, $this->adminApi('GET', '/tax-rate/list')->json('data.list'));
    }

    public function test_exclusive_tax_is_frozen_on_the_order_and_follows_refund_and_points(): void
    {
        app(ConfigService::class)->save('trade', ['points_earn_enabled' => 1, 'points_earn_per_unit' => 1]);
        $rule = $this->rule(['country_code' => 'US', 'province' => '', 'rate' => '10', 'shipping_taxable' => 1]);
        $sku = $this->shippedSku('100.00');
        $addr = $this->address('US', 'Texas');

        // 2 件：商品 200，运费 15，税 = 20 + 1.5，应付 236.5
        $p = $this->preview($sku, 2, $addr);
        $this->assertSame(['200.00', '15.00', '21.50', '236.50', 200], [$p['goods_amount'], $p['shipping_fee'], $p['tax_amount'], $p['pay_amount'], $p['earn_points']]);
        $order = $this->placeOrder($this->token, $sku, 2, $addr);
        $this->assertSame(['21.50', '1.50', '10.000', 'Sales Tax', 0, '236.50'], [
            (string) $order->tax_amount, (string) $order->tax_shipping, (string) $order->tax_rate, $order->tax_name, (int) $order->tax_included, (string) $order->pay_amount,
        ]);

        // 之后改税率不影响这张订单
        $this->adminApi('PUT', "/tax-rate/{$rule}", ['country_code' => 'US', 'province' => '', 'name' => 'Sales Tax', 'rate' => '20', 'included' => 0, 'shipping_taxable' => 1, 'status' => 1])->assertJsonPath('code', 0);
        $detail = $this->api('GET', "/orders/{$order->order_no}", [], $this->token)->json('data');
        $this->assertSame(['21.50', 'Sales Tax', '10', 0], [$detail['tax_amount'], $detail['tax_name'], $detail['tax_rate'], $detail['tax_included']]);

        // 退 1 件：这件的税一起退（110），运费和运费的税不退；整单退款是全部实付
        $order = $this->pay($order);
        $info = $this->api('GET', "/orders/{$order->order_no}/refundable", [], $this->token)->json('data');
        $this->assertSame(['220.00', '236.50'], [$info['items'][0]['max_amount'], $info['whole_order']['amount']]);
        $this->assertSame(['16.50', '220.00'], [TaxService::shippingPaid($order), TaxService::goodsPaid($order)]);

        // 完成后送积分：税不算消费，按 200 送
        DB::transaction(function () use ($order) {
            $status = app(OrderStatusService::class);
            $status->transit($order, [OrderStatus::TO_SHIP], OrderStatus::SHIPPED, OrderStatus::OP_SYSTEM, 0, '测试发货');
            $status->transit($order->fresh(), [OrderStatus::SHIPPED], OrderStatus::FINISHED, OrderStatus::OP_SYSTEM, 0, '测试收货', ['finished_at' => now()]);
        });
        $this->assertSame(200, (int) DB::table('member_point_log')->where('ref_no', $order->order_no)->where('type', 3)->value('change_points'));
    }

    public function test_inclusive_tax_does_not_change_what_the_buyer_pays(): void
    {
        $this->rule(['country_code' => 'GB', 'province' => '', 'rate' => '20', 'name' => 'VAT', 'included' => 1, 'shipping_taxable' => 1]);
        $sku = $this->shippedSku('120.00');
        $order = $this->placeOrder($this->token, $sku, 1, $this->address('GB'));

        // 商品 120 含 20，运费 10 含 1.67；应付还是 130
        $this->assertSame(['21.67', '1.67', 1, '130.00'], [(string) $order->tax_amount, (string) $order->tax_shipping, (int) $order->tax_included, (string) $order->pay_amount]);
        $this->assertSame(['10.00', '120.00'], [TaxService::shippingPaid($order), TaxService::goodsPaid($order)]);
        $this->assertSame('100.00', TaxService::exTax($order, '100.00'), '价内税不从消费里扣');
    }

    public function test_admin_price_change_recalculates_tax(): void
    {
        $this->rule(['country_code' => 'US', 'province' => '', 'rate' => '10', 'shipping_taxable' => 1]);
        $sku = $this->shippedSku('100.00');
        $order = $this->placeOrder($this->token, $sku, 1, $this->address('US', 'Texas'));
        // 商品 100 + 运费 10 + 税 11 = 121
        $this->assertSame('121.00', (string) $order->pay_amount);

        // 上限是不打折时的应付，下限要比运费（含运费的税）多
        $this->assertSame('改后金额须在 11.01 ~ 121.00 之间', $this->adminApi('POST', "/order/list/{$order->id}/price", ['pay_amount' => '11.00'])->json('msg'));
        $this->assertSame('改后金额须在 11.01 ~ 121.00 之间', $this->adminApi('POST', "/order/list/{$order->id}/price", ['pay_amount' => '121.01'])->json('msg'));

        // 改成 99：商品部分 88 = 净额 80 + 税 8；优惠 20，税 8 + 1 = 9，恒等式仍然成立
        $this->adminApi('POST', "/order/list/{$order->id}/price", ['pay_amount' => '99.00'])->assertJsonPath('code', 0);
        $o = Order::query()->find($order->id);
        $this->assertSame(['99.00', '20.00', '9.00', '1.00'], [(string) $o->pay_amount, (string) $o->discount_amount, (string) $o->tax_amount, (string) $o->tax_shipping]);
        $this->assertSame((string) $o->pay_amount, bcadd(bcsub(bcadd((string) $o->goods_amount, (string) $o->shipping_fee, 2), (string) $o->discount_amount, 2), (string) $o->tax_amount, 2));
    }
}
