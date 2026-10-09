<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Common\OrderRefundService;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 商家代买家发起售后：直接视为审核通过，不受售后申请期限限制，其余规则和买家申请相同 */
class AdminRefundApplyTest extends FeatureTestCase
{
    private string $token;

    private int $sku;

    private Order $order;

    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        [$m, $this->token] = $this->makeMember();
        $this->sku = $this->makeSku('100.00', 10);
        $this->order = $this->pay($this->placeOrder($this->token, $this->sku, 3, (int) $this->makeAddress($m->id)->id));
        $this->itemId = (int) DB::table('order_item')->where('order_id', $this->order->id)->value('id');
    }

    private function apply(array $data)
    {
        return $this->adminApi('POST', "/order/list/{$this->order->id}/refund", $data);
    }

    private function ship(): void
    {
        $this->adminApi('POST', "/order/ship/{$this->order->id}", ['express_code' => 'SF', 'express_no' => 'SF1'])->assertJsonPath('code', 0);
    }

    public function test_whole_order_refund_started_by_the_merchant_is_already_approved(): void
    {
        $info = $this->adminApi('GET', "/order/list/{$this->order->id}/refundable");
        $this->assertSame([true, '300.00', $this->order->order_no], [$info->json('data.whole_order.allowed'), $info->json('data.whole_order.amount'), $info->json('data.order_no')]);

        $r = $this->apply(['type' => 1, 'reason' => '缺货']);
        $r->assertJsonPath('code', 0);

        // 已是“退款中”，订单进入售后中；日志记的是管理员
        $refund = OrderRefund::query()->findOrFail($r->json('data.id'));
        $this->assertSame([OrderRefund::REFUNDING, '300.00', '商家发起：缺货'], [(int) $refund->status, (string) $refund->refund_amount, $refund->reason]);
        $this->assertSame(OrderStatus::AFTER_SALE, (int) $this->order->fresh()->status);
        $this->assertDatabaseHas('order_status_log', ['order_id' => $this->order->id, 'to_status' => OrderStatus::AFTER_SALE, 'operator_type' => OrderStatus::OP_ADMIN]);

        // 执行退款后订单关闭、库存回补
        $this->adminApi('POST', "/order/refund/{$refund->id}/pay", ['offline' => 1])->assertJsonPath('code', 0);
        $order = $this->order->fresh();
        $this->assertSame([OrderStatus::CLOSED, OrderStatus::PAY_REFUNDED], [(int) $order->status, (int) $order->pay_status]);
        $this->assertSame(10, $this->stock($this->sku));

        // 买家能在自己的售后列表里看到这张单
        $this->assertSame($refund->refund_no, $this->api('GET', '/refunds', [], $this->token)->json('data.list.0.refund_no'));
    }

    public function test_partial_refund_with_a_custom_amount_and_the_usual_limits(): void
    {
        $item = fn (array $over = []) => $over + ['order_item_id' => $this->itemId, 'qty' => 1, 'type' => 1, 'reason' => '补偿'];

        // 数量、金额超限，未发货选退货退款，缺原因：都不行
        $this->apply($item(['qty' => 4]))->assertJsonPath('code', 1);
        $this->apply($item(['amount' => '100.01']))->assertJsonPath('code', 1);
        $this->apply($item(['type' => 2]))->assertJsonPath('code', 1);
        $this->apply($item(['reason' => '']))->assertJsonPath('code', 422);
        $this->assertSame(0, OrderRefund::query()->count());

        // 只退 30
        $r = $this->apply($item(['amount' => '30']));
        $r->assertJsonPath('code', 0);
        $this->assertSame('30.00', (string) OrderRefund::query()->whereKey($r->json('data.id'))->value('refund_amount'));
        app(OrderRefundService::class)->pay((int) $r->json('data.id'), 1, true);

        $order = $this->order->fresh();
        $this->assertSame([OrderStatus::TO_SHIP, OrderStatus::PAY_PART_REFUND], [(int) $order->status, (int) $order->pay_status]);
    }

    public function test_return_refund_waits_for_the_buyer_to_send_the_goods_back(): void
    {
        $this->ship();

        $r = $this->apply(['order_item_id' => $this->itemId, 'qty' => 2, 'type' => 2, 'reason' => '质量问题']);
        $r->assertJsonPath('code', 0);
        $r->assertJsonPath('data.status', OrderRefund::WAIT_RETURN);

        // 买家照常填写退货物流，商家确认收货后退款
        $this->api('POST', "/refunds/{$r->json('data.refund_no')}/return", ['express' => '顺丰', 'express_no' => 'SF-BACK'], $this->token)->assertJsonPath('code', 0);
        $this->adminApi('POST', "/order/refund/{$r->json('data.id')}/confirm")->assertJsonPath('code', 0);
        $this->adminApi('POST', "/order/refund/{$r->json('data.id')}/pay", ['offline' => 1])->assertJsonPath('code', 0);
        $this->assertSame(9, $this->stock($this->sku));
    }

    public function test_the_merchant_can_refund_after_the_buyers_window_has_closed(): void
    {
        $this->ship();
        $this->api('POST', "/orders/{$this->order->order_no}/receive", [], $this->token)->assertJsonPath('code', 0);
        DB::table('order')->where('id', $this->order->id)->update(['finished_at' => now()->subDays(30)]);
        $data = ['order_item_id' => $this->itemId, 'qty' => 1, 'type' => 1, 'reason' => '超期特批'];

        // 买家已经不能申请
        $this->api('POST', "/orders/{$this->order->order_no}/refunds", $data, $this->token)->assertJsonPath('code', 1);
        // 商家可以
        $this->assertSame(3, $this->adminApi('GET', "/order/list/{$this->order->id}/refundable")->json('data.items.0.available_qty'));
        $this->apply($data)->assertJsonPath('code', 0);
    }

    public function test_orders_that_are_unpaid_or_fully_refunded_cannot_be_refunded(): void
    {
        [$m, $token] = $this->makeMember();
        $unpaid = $this->placeOrder($token, $this->sku, 1, (int) $this->makeAddress($m->id)->id);
        $this->adminApi('POST', "/order/list/{$unpaid->id}/refund", ['type' => 1, 'reason' => 'x'])->assertJsonPath('code', 1);

        $first = $this->apply(['type' => 1, 'reason' => '全退']);
        app(OrderRefundService::class)->pay((int) $first->json('data.id'), 1, true);
        $this->apply(['type' => 1, 'reason' => '再退'])->assertJsonPath('code', 1);
        $this->assertSame(1, OrderRefund::query()->count());

        $this->adminApi('POST', '/order/list/999999/refund', ['type' => 1, 'reason' => 'x'])->assertJsonPath('code', 1);
    }
}
