<?php

namespace Tests\Feature;

use App\Models\DistributionCommission;
use App\Models\Distributor;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Common\ConfigService;
use App\Services\Common\DistributionService;
use App\Services\Common\OrderRefundService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FeatureTestCase;

/** 按商品单独设置分销佣金：单独比例、不参与分销、一张订单里比例不同时的计算与退款 */
class GoodsCommissionTest extends FeatureTestCase
{
    private int $parentId;

    private int $grandId;

    private string $buyerToken;

    private int $addressId;

    protected function setUp(): void
    {
        parent::setUp();
        app(ConfigService::class)->save('distribution', ['enabled' => 1, 'level' => 2, 'rate_1' => '10', 'rate_2' => '5', 'min_withdraw' => '10']);

        [$grand] = $this->makeMember();
        [$parent] = $this->makeMember(['parent_id' => $grand->id]);
        [$buyer, $this->buyerToken] = $this->makeMember(['parent_id' => $parent->id]);
        foreach ([$grand, $parent] as $m) {
            app(DistributionService::class)->appoint((int) $m->id);
        }
        [$this->grandId, $this->parentId] = [(int) $grand->id, (int) $parent->id];
        $this->addressId = (int) $this->makeAddress($buyer->id)->id;
    }

    /** @param array<int,int> $items SKU ID => 数量 */
    private function buy(array $items): Order
    {
        $lines = [];
        foreach ($items as $sku => $qty) {
            $lines[] = ['sku_id' => $sku, 'qty' => $qty];
        }
        $r = $this->api('POST', '/orders', ['items' => $lines, 'address_id' => $this->addressId], $this->buyerToken);
        $this->assertSame(0, $r->json('code'), (string) $r->json('msg'));

        return $this->pay(Order::query()->where('order_no', $r->json('data.order_no'))->firstOrFail());
    }

    /** @return array<int,array{0:string,1:string,2:string}> 层级 => [基数, 比例, 佣金] */
    private function rows(Order $order): array
    {
        return DistributionCommission::query()->where('order_id', $order->id)->orderBy('level')->get()
            ->mapWithKeys(fn ($c) => [(int) $c->level => [(string) $c->base_amount, (string) $c->rate, (string) $c->amount]])->all();
    }

    public function test_a_product_with_its_own_rates_overrides_the_global_ones(): void
    {
        $sku = $this->makeSku('100.00', 10, ['commission_type' => 1, 'commission_rate_1' => '20', 'commission_rate_2' => '8']);

        $order = $this->buy([$sku => 2]);

        $this->assertSame([1 => ['200.00', '20.00', '40.00'], 2 => ['200.00', '8.00', '16.00']], $this->rows($order));
        $this->assertSame('40.00', (string) Distributor::query()->find($this->parentId)->pending_amount);
        $this->assertSame('16.00', (string) Distributor::query()->find($this->grandId)->pending_amount);
    }

    public function test_a_zero_rate_on_one_level_pays_nothing_for_that_level_only(): void
    {
        $sku = $this->makeSku('100.00', 10, ['commission_type' => 1, 'commission_rate_1' => '15', 'commission_rate_2' => '0']);

        $this->assertSame([1 => ['100.00', '15.00', '15.00']], $this->rows($this->buy([$sku => 1])));
    }

    public function test_products_excluded_from_distribution_earn_no_commission(): void
    {
        $excluded = $this->makeSku('300.00', 10, ['commission_type' => 2]);
        $normal = $this->makeSku('100.00', 10);

        // 只买不参与分销的商品：不产生佣金
        $this->assertSame([], $this->rows($this->buy([$excluded => 1])));

        // 混在一张订单里：只有参与分销的那 100 计佣
        $this->assertSame([1 => ['100.00', '10.00', '10.00'], 2 => ['100.00', '5.00', '5.00']], $this->rows($this->buy([$excluded => 1, $normal => 1])));
    }

    public function test_mixed_rates_in_one_order_are_summed_per_product_and_refunds_scale_the_total(): void
    {
        $custom = $this->makeSku('100.00', 10, ['commission_type' => 1, 'commission_rate_1' => '20', 'commission_rate_2' => '8']);
        $normal = $this->makeSku('300.00', 10);

        // 一级 100×20% + 300×10% = 50；二级 100×8% + 300×5% = 23。比例列是折算的综合比例
        $order = $this->buy([$custom => 1, $normal => 1]);
        $this->assertSame([1 => ['400.00', '12.50', '50.00'], 2 => ['400.00', '5.75', '23.00']], $this->rows($order));

        // 生成之后再改商品的佣金设置，不影响这笔订单
        DB::table('goods')->update(['commission_type' => 2]);

        // 退掉 100 的那件（商品款的四分之一）：佣金整体按比例减少四分之一
        $itemId = (int) DB::table('order_item')->where('order_id', $order->id)->where('sku_id', $custom)->value('id');
        $r = $this->api('POST', "/orders/{$order->order_no}/refunds", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '测试'], $this->buyerToken);
        $id = (int) OrderRefund::query()->where('refund_no', $r->json('data.refund_no'))->value('id');
        app(OrderRefundService::class)->approve($id, 1);
        app(OrderRefundService::class)->pay($id, 1, true);

        $this->assertSame([1 => ['400.00', '12.50', '37.50'], 2 => ['400.00', '5.75', '17.25']], $this->rows($order));
        $this->assertSame('37.50', (string) Distributor::query()->find($this->parentId)->pending_amount);
        $this->assertSame('50.00', (string) DistributionCommission::query()->where('order_id', $order->id)->where('level', 1)->value('origin_amount'));
    }

    public function test_admin_can_save_the_commission_setting_on_a_product(): void
    {
        $sku = $this->makeSku('100.00', 10);
        $goodsId = (int) DB::table('goods_sku')->where('id', $sku)->value('goods_id');
        $detail = $this->adminApi('GET', "/goods/{$goodsId}");
        $detail->assertJsonPath('code', 0);
        $this->assertSame([0, '0.00'], [$detail->json('data.commission_type'), $detail->json('data.commission_rate_1')]);
        $save = fn (array $over) => $this->adminApi('PUT', "/goods/{$goodsId}", $over + $detail->json('data'));

        $save(['commission_type' => 1, 'commission_rate_1' => 12.5, 'commission_rate_2' => 3])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('goods', ['id' => $goodsId, 'commission_type' => 1, 'commission_rate_1' => '12.50', 'commission_rate_2' => '3.00']);

        // 超过 50% 不允许
        $save(['commission_type' => 1, 'commission_rate_1' => 50.01, 'commission_rate_2' => 3])->assertJsonPath('code', 422);

        // 改回“跟随全局”或“不参与”时比例清零，避免留下看不见的旧值
        $save(['commission_type' => 2, 'commission_rate_1' => 12.5, 'commission_rate_2' => 3])->assertJsonPath('code', 0);
        $this->assertDatabaseHas('goods', ['id' => $goodsId, 'commission_type' => 2, 'commission_rate_1' => '0.00', 'commission_rate_2' => '0.00']);
    }

    #[DataProvider('calcCases')]
    public function test_commission_by_goods(string $base, array $lines, int $level, array $expected): void
    {
        $lines = array_map(fn ($l) => ['amount' => $l[0], 'type' => $l[1], 'rate_1' => $l[2] ?? '0', 'rate_2' => $l[3] ?? '0'], $lines);

        $this->assertSame($expected, array_values(DistributionService::commissionByGoods($base, $lines, $level, $level === 1 ? '10' : '5')));
    }

    public static function calcCases(): array
    {
        return [
            '全部跟随全局：和整单 × 全局比例一致' => ['299.70', [['199.80', 0], ['99.90', 0]], 1, ['299.70', '10.00', '29.97']],
            '多行同一比例不因逐行摊分产生误差' => ['100.00', [['33.33', 0], ['33.33', 0], ['33.34', 0]], 1, ['100.00', '10.00', '10.00']],
            '有会员折扣：基数按行金额占比摊分' => ['360.00', [['100.00', 1, '20', '8'], ['300.00', 0]], 1, ['360.00', '12.50', '45.00']],
            '二级用商品自己的二级比例' => ['400.00', [['100.00', 1, '20', '8'], ['300.00', 0]], 2, ['400.00', '5.75', '23.00']],
            '不参与分销的行不计入基数' => ['400.00', [['300.00', 2], ['100.00', 0]], 1, ['100.00', '10.00', '10.00']],
            '全部不参与' => ['400.00', [['400.00', 2]], 1, ['0.00', '0.00', '0.00']],
            '基数为 0' => ['0.00', [['100.00', 0]], 1, ['0.00', '0.00', '0.00']],
        ];
    }
}
