<?php

namespace Tests\Feature;

use App\Models\DistributionCommission;
use App\Models\DistributionWithdraw;
use App\Models\Distributor;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Common\ConfigService;
use App\Services\Common\OrderPaidService;
use App\Services\Common\OrderRefundService;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 分销：两级佣金生成、退款扣减、到期结算、提现的冻结 / 驳回 / 打款 */
class DistributionFlowTest extends FeatureTestCase
{
    private Member $grand;

    private Member $parent;

    private Member $buyer;

    private string $parentToken;

    private string $buyerToken;

    private int $addressId;

    private int $sku;

    protected function setUp(): void
    {
        parent::setUp();
        app(ConfigService::class)->save('distribution', ['enabled' => 1, 'level' => 2, 'rate_1' => '10', 'rate_2' => '5', 'min_withdraw' => '10']);

        // 关系链：grand ← parent ← buyer，前两位是分销员
        [$this->grand] = $this->makeMember();
        [$this->parent, $this->parentToken] = $this->makeMember(['parent_id' => $this->grand->id]);
        [$this->buyer, $this->buyerToken] = $this->makeMember(['parent_id' => $this->parent->id]);
        foreach ([$this->grand, $this->parent] as $m) {
            $this->adminApi('POST', "/distribution/distributor/{$m->id}/appoint")->assertJsonPath('code', 0);
        }
        $this->addressId = (int) $this->makeAddress($this->buyer->id)->id;
        $this->sku = $this->makeSku('100.00', 20);
    }

    private function buy(int $qty = 2): Order
    {
        return $this->pay($this->placeOrder($this->buyerToken, $this->sku, $qty, $this->addressId));
    }

    private function account(Member $m): Distributor
    {
        return Distributor::query()->findOrFail($m->id);
    }

    /** @return array<int,string> 层级 => 当前佣金 */
    private function commissions(Order $order): array
    {
        return DistributionCommission::query()->where('order_id', $order->id)->orderBy('level')->pluck('amount', 'level')->map(fn ($v) => (string) $v)->all();
    }

    /** 买家申请 → 商家同意 → 登记线下已退款 */
    private function refund(Order $order, array $data): void
    {
        $r = $this->api('POST', "/orders/{$order->order_no}/refunds", $data + ['type' => 1, 'reason' => '测试'], $this->buyerToken);
        $this->assertSame(0, $r->json('code'), (string) $r->json('msg'));
        $id = (int) OrderRefund::query()->where('refund_no', $r->json('data.refund_no'))->value('id');
        app(OrderRefundService::class)->approve($id, 1);
        app(OrderRefundService::class)->pay($id, 1, true);
    }

    /** 发货、确认收货，并把完成时间挪到售后期之外 */
    private function finishLongAgo(Order $order): void
    {
        $this->adminApi('POST', "/order/ship/{$order->id}", ['express_code' => 'SF', 'express_no' => 'SF1'])->assertJsonPath('code', 0);
        $this->api('POST', "/orders/{$order->order_no}/receive", [], $this->buyerToken)->assertJsonPath('code', 0);
        DB::table('order')->where('id', $order->id)->update(['finished_at' => now()->subDays(8)]);
    }

    public function test_paying_an_order_creates_commission_for_both_levels_exactly_once(): void
    {
        $order = $this->buy(2);

        $this->assertSame([1 => '20.00', 2 => '10.00'], $this->commissions($order));
        $this->assertSame('20.00', (string) $this->account($this->parent)->pending_amount);
        $this->assertSame('10.00', (string) $this->account($this->grand)->pending_amount);
        $this->assertSame((int) $this->parent->id, (int) $order->fresh()->source_member_id);

        // 重复标记已支付不会再生成佣金
        app(OrderPaidService::class)->markPaid((int) $order->id, 'offline');
        $this->assertSame(2, DistributionCommission::query()->where('order_id', $order->id)->count());
        $this->assertSame('20.00', (string) $this->account($this->parent)->pending_amount);
    }

    public function test_shipping_fee_is_not_part_of_the_commission_base(): void
    {
        $tpl = DB::table('shipping_template')->insertGetId(['name' => '测试模板', 'charge_type' => 1, 'is_free_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shipping_template_region')->insert(['template_id' => $tpl, 'countries' => 'CN', 'provinces' => '', 'first_unit' => 1, 'first_fee' => 10, 'step_unit' => 1, 'step_fee' => 5, 'free_amount' => 0]);
        $this->sku = $this->makeSku('100.00', 20, ['shipping_template_id' => $tpl]);

        $order = $this->buy(1);

        $this->assertSame('110.00', (string) $order->pay_amount);
        $this->assertSame([1 => '10.00', 2 => '5.00'], $this->commissions($order));
    }

    public function test_no_commission_when_the_referrer_is_not_an_active_distributor_or_the_feature_is_off(): void
    {
        // 上级被停用：一级不计佣，二级照常
        $this->adminApi('POST', "/distribution/distributor/{$this->parent->id}/status", ['status' => 0])->assertJsonPath('code', 0);
        $this->assertSame([2 => '10.00'], $this->commissions($this->buy(2)));

        // 关闭分销后不再产生佣金
        app(ConfigService::class)->save('distribution', ['enabled' => 0]);
        $this->assertSame([], $this->commissions($this->buy(2)));

        // 只开一级
        app(ConfigService::class)->save('distribution', ['enabled' => 1, 'level' => 1]);
        $this->adminApi('POST', "/distribution/distributor/{$this->parent->id}/status", ['status' => 1])->assertJsonPath('code', 0);
        $this->assertSame([1 => '20.00'], $this->commissions($this->buy(2)));
    }

    public function test_refunds_reduce_pending_commission_and_void_it_when_everything_is_refunded(): void
    {
        $order = $this->buy(2);
        $itemId = (int) DB::table('order_item')->where('order_id', $order->id)->value('id');

        // 退掉一半：佣金减半
        $this->refund($order, ['order_item_id' => $itemId, 'qty' => 1]);
        $this->assertSame([1 => '10.00', 2 => '5.00'], $this->commissions($order));
        $this->assertSame('10.00', (string) $this->account($this->parent)->pending_amount);

        // 全部退完：佣金作废
        $this->refund($order, ['order_item_id' => $itemId, 'qty' => 1]);
        $this->assertSame([1 => '0.00', 2 => '0.00'], $this->commissions($order));
        $this->assertSame(2, DistributionCommission::query()->where('order_id', $order->id)->where('status', DistributionCommission::VOID)->count());
        $this->assertSame('0.00', (string) $this->account($this->parent)->pending_amount);
        $this->assertSame('0.00', (string) $this->account($this->grand)->pending_amount);
    }

    public function test_commission_settles_only_after_the_after_sale_window_and_only_once(): void
    {
        $order = $this->buy(2);

        // 未完成、或刚完成还在售后期内：不结算
        Artisan::call('mall:distribution-settle');
        $this->adminApi('POST', "/order/ship/{$order->id}", ['express_code' => 'SF', 'express_no' => 'SF1'])->assertJsonPath('code', 0);
        $this->api('POST', "/orders/{$order->order_no}/receive", [], $this->buyerToken)->assertJsonPath('code', 0);
        Artisan::call('mall:distribution-settle');
        $this->assertSame('0.00', (string) $this->account($this->parent)->available_amount);

        // 过了售后期但还有一张售后单没处理完：也不结算
        $itemId = (int) DB::table('order_item')->where('order_id', $order->id)->value('id');
        $open = $this->api('POST', "/orders/{$order->order_no}/refunds", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '测试'], $this->buyerToken);
        $open->assertJsonPath('code', 0);
        DB::table('order')->where('id', $order->id)->update(['finished_at' => now()->subDays(8)]);
        Artisan::call('mall:distribution-settle');
        $this->assertSame('0.00', (string) $this->account($this->parent)->available_amount);

        // 买家撤销售后，订单回到已完成：可以结算了
        $this->api('POST', "/refunds/{$open->json('data.refund_no')}/cancel", [], $this->buyerToken)->assertJsonPath('code', 0);
        $this->assertSame(OrderStatus::FINISHED, (int) $order->fresh()->status);
        DB::table('order')->where('id', $order->id)->update(['finished_at' => now()->subDays(8)]);

        Artisan::call('mall:distribution-settle');
        $parent = $this->account($this->parent);
        $this->assertSame(['0.00', '20.00'], [(string) $parent->pending_amount, (string) $parent->available_amount]);
        $this->assertSame('10.00', (string) $this->account($this->grand)->available_amount);
        $this->assertDatabaseHas('distribution_log', ['member_id' => $this->parent->id, 'type' => 1, 'change_amount' => '20.00', 'after_amount' => '20.00', 'ref_no' => $order->order_no]);

        // 再跑一次不会重复入账
        Artisan::call('mall:distribution-settle');
        $this->assertSame('20.00', (string) $this->account($this->parent)->available_amount);
        $this->assertSame(1, DB::table('distribution_log')->where('member_id', $this->parent->id)->count());
    }

    public function test_a_refund_after_settlement_claws_the_commission_back(): void
    {
        $order = $this->buy(2);
        $this->finishLongAgo($order);
        Artisan::call('mall:distribution-settle');
        $this->assertSame('20.00', (string) $this->account($this->parent)->available_amount);

        // 把完成时间挪回售后期内，模拟结算之后才发生的退款
        DB::table('order')->where('id', $order->id)->update(['finished_at' => now()]);
        $this->refund($order, ['order_item_id' => (int) DB::table('order_item')->where('order_id', $order->id)->value('id'), 'qty' => 1]);

        $this->assertSame([1 => '10.00', 2 => '5.00'], $this->commissions($order));
        $this->assertSame('10.00', (string) $this->account($this->parent)->available_amount);
        $this->assertDatabaseHas('distribution_log', ['member_id' => $this->parent->id, 'type' => 2, 'change_amount' => '-10.00', 'after_amount' => '10.00']);
    }

    public function test_withdrawal_freezes_the_balance_and_follows_reject_approve_paid(): void
    {
        $this->finishLongAgo($this->buy(2));
        Artisan::call('mall:distribution-settle');
        $apply = fn (string $amount) => $this->api('POST', '/distribution/withdraws', ['amount' => $amount, 'method' => 'alipay', 'account' => ['name' => '张三', 'no' => 'zs@example.com']], $this->parentToken);
        $balances = function (): array {
            $d = $this->account($this->parent);

            return [(string) $d->available_amount, (string) $d->frozen_amount, (string) $d->withdrawn_amount];
        };

        // 低于最低提现额、超过余额都不行
        $apply('5')->assertJsonPath('code', 1);
        $apply('20.01')->assertJsonPath('code', 1);
        $this->assertSame(['20.00', '0.00', '0.00'], $balances());

        // 申请 15：余额冻结；处理完之前不能再申请
        $apply('15')->assertJsonPath('code', 0);
        $this->assertSame(['5.00', '15.00', '0.00'], $balances());
        $apply('5')->assertJsonPath('code', 1);
        $first = DistributionWithdraw::query()->where('member_id', $this->parent->id)->firstOrFail();
        $this->assertStringNotContainsString('zs@example.com', (string) DB::table('distribution_withdraw')->where('id', $first->id)->value('account_info'), '收款信息应加密保存');

        // 没审核通过不能登记打款
        $this->adminApi('POST', "/distribution/withdraw/{$first->id}/pay", ['pay_remark' => 'x'])->assertJsonPath('code', 1);

        // 驳回：冻结的钱退回可提现
        $this->adminApi('POST', "/distribution/withdraw/{$first->id}/audit", ['pass' => 0, 'reject_reason' => '收款信息有误'])->assertJsonPath('code', 0);
        $this->assertSame(['20.00', '0.00', '0.00'], $balances());
        $this->adminApi('POST', "/distribution/withdraw/{$first->id}/audit", ['pass' => 1])->assertJsonPath('code', 1);

        // 再申请 20：通过 → 打款
        $apply('20')->assertJsonPath('code', 0);
        $second = DistributionWithdraw::query()->where('member_id', $this->parent->id)->orderByDesc('id')->firstOrFail();
        $this->adminApi('POST', "/distribution/withdraw/{$second->id}/audit", ['pass' => 1])->assertJsonPath('code', 0);
        $this->adminApi('POST', "/distribution/withdraw/{$second->id}/pay", ['pay_remark' => '转账流水 001'])->assertJsonPath('code', 0);
        $this->assertSame(['0.00', '0.00', '20.00'], $balances());
        $this->assertSame(DistributionWithdraw::PAID, (int) $second->fresh()->status);

        // 已打款的不能重复登记
        $this->adminApi('POST', "/distribution/withdraw/{$second->id}/pay", ['pay_remark' => '再来'])->assertJsonPath('code', 1);
        $this->assertSame(['0.00', '0.00', '20.00'], $balances());
    }

    public function test_only_distributors_can_withdraw(): void
    {
        $r = $this->api('POST', '/distribution/withdraws', ['amount' => '10', 'method' => 'alipay', 'account' => ['name' => '张三', 'no' => 'a']], $this->buyerToken);

        $r->assertJsonPath('code', 1);
        $this->assertSame(0, DistributionWithdraw::query()->count());
    }
}
