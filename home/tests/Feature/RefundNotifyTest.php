<?php

namespace Tests\Feature;

use App\Drivers\Contracts\PayDriver;
use App\Drivers\DriverManager;
use App\Drivers\Pay\Dto\NotifyResult;
use App\Drivers\Pay\Dto\PayRequest;
use App\Drivers\Pay\Dto\PayResult;
use App\Drivers\Pay\Dto\QueryResult;
use App\Drivers\Pay\Dto\RefundRequest;
use App\Drivers\Pay\Dto\RefundResult;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PayNotifyLog;
use App\Models\PayTrade;
use App\Support\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\FeatureTestCase;

/** 在线支付的退款：渠道异步处理时靠查询、回调和补偿任务完成售后单 */
class RefundNotifyTest extends FeatureTestCase
{
    /** 假渠道的状态：refund() 和 queryRefund() 返回什么、各被调了几次 */
    private object $channel;

    private Order $order;

    private OrderRefund $refund;

    private int $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = (object) ['refund' => 'pending', 'query' => 'pending', 'refundCalls' => 0, 'queryCalls' => 0];
        $fake = new class($this->channel) implements PayDriver
        {
            public function __construct(private object $state) {}

            public function code(): string
            {
                return 'stripe';
            }

            public function create(PayRequest $req): PayResult
            {
                throw new RuntimeException('未用到');
            }

            public function verifyNotify(Request $request): NotifyResult
            {
                return $this->parse($request->getContent());
            }

            /** 回调报文就是一段 JSON，字段原样变成解析结果 */
            public function parse(string $body): NotifyResult
            {
                $d = json_decode($body, true);

                return new NotifyResult(
                    verified: true, eventId: (string) $d['event_id'], tradeNo: (string) ($d['trade_no'] ?? ''), channelTradeNo: 'cs_1',
                    status: (string) ($d['status'] ?? 'success'), currency: (string) ($d['currency'] ?? 'CNY'), amount: (string) ($d['amount'] ?? '0'),
                    rawBody: $body, refundNo: (string) ($d['refund_no'] ?? ''), channelRefundNo: (string) ($d['channel_refund_no'] ?? ''),
                );
            }

            public function confirm(string $tradeNo, string $channelTradeNo): QueryResult
            {
                throw new RuntimeException('未用到');
            }

            public function query(string $tradeNo, string $channelTradeNo): QueryResult
            {
                throw new RuntimeException('未用到');
            }

            public function refund(RefundRequest $req): RefundResult
            {
                $this->state->refundCalls++;

                return $this->result($this->state->refund);
            }

            public function queryRefund(string $refundNo, string $channelRefundNo): RefundResult
            {
                $this->state->queryCalls++;

                return $this->result($this->state->query);
            }

            private function result(string $status): RefundResult
            {
                return new RefundResult(success: $status === 'success', channelRefundNo: 're_1', pending: $status === 'pending');
            }

            public function ping(): array
            {
                return [];
            }
        };
        $manager = Mockery::mock(DriverManager::class)->makePartial();
        $manager->shouldReceive('payForNotify')->andReturn($fake);
        $manager->shouldReceive('pay')->andReturn($fake);
        $this->app->instance(DriverManager::class, $manager);

        // 一张在线支付（经回调入账）的订单，买 2 件，申请退 1 件并审核通过
        [$m, $token] = $this->makeMember();
        $this->sku = $this->makeSku('100.00', 10);
        $this->order = $this->placeOrder($token, $this->sku, 2, (int) $this->makeAddress($m->id)->id);
        PayTrade::query()->create([
            'trade_no' => 'PT1', 'order_id' => $this->order->id, 'member_id' => $m->id, 'channel' => 'stripe', 'channel_trade_no' => '',
            'trade_type' => 1, 'currency' => 'CNY', 'amount' => $this->order->pay_amount, 'status' => PayTrade::PENDING,
        ]);
        $this->notify(['event_id' => 'evt_pay', 'trade_no' => 'PT1', 'amount' => '200.00'])->assertStatus(200);
        $this->assertSame(OrderStatus::PAY_PAID, (int) $this->order->fresh()->pay_status);

        $itemId = (int) DB::table('order_item')->where('order_id', $this->order->id)->value('id');
        $r = $this->adminApi('POST', "/order/list/{$this->order->id}/refund", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '协商退款']);
        $this->refund = OrderRefund::query()->findOrFail($r->json('data.id'));
    }

    private function notify(array $payload)
    {
        return $this->call('POST', '/notify/pay/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    }

    private function payRefund()
    {
        return $this->adminApi('POST', "/order/refund/{$this->refund->id}/pay", ['offline' => 0]);
    }

    private function refundEvent(array $over = []): array
    {
        return $over + ['event_id' => 'evt_refund', 'status' => 'refunded', 'refund_no' => $this->refund->refund_no, 'channel_refund_no' => 're_1', 'amount' => '100.00', 'currency' => 'CNY'];
    }

    private function assertRefundDone(): void
    {
        $this->assertSame(OrderRefund::DONE, (int) $this->refund->fresh()->status);
        $order = $this->order->fresh();
        $this->assertSame([OrderStatus::TO_SHIP, OrderStatus::PAY_PART_REFUND], [(int) $order->status, (int) $order->pay_status]);
        $this->assertSame(9, $this->stock($this->sku));
        $this->assertSame(1, PayTrade::query()->where('order_id', $order->id)->where('trade_type', 2)->count(), '退款流水只写一条');
    }

    public function test_a_pending_refund_is_queried_not_resent_when_the_admin_clicks_again(): void
    {
        $first = $this->payRefund();
        $first->assertJsonPath('data.done', false);
        $this->assertSame([OrderRefund::REFUNDING, 're_1'], [(int) $this->refund->fresh()->status, $this->refund->fresh()->refund_trade_no]);

        // 再点：只查询，不会重新发起退款
        $this->payRefund()->assertJsonPath('data.done', false);
        $this->channel->query = 'success';
        $this->payRefund()->assertJsonPath('data.done', true);

        $this->assertSame([1, 2], [$this->channel->refundCalls, $this->channel->queryCalls]);
        $this->assertRefundDone();
    }

    public function test_the_refund_callback_completes_the_after_sale_and_replays_are_harmless(): void
    {
        $this->payRefund()->assertJsonPath('data.done', false);

        $this->notify($this->refundEvent())->assertStatus(200);
        $this->assertRefundDone();
        $this->assertDatabaseHas('order_status_log', ['order_id' => $this->order->id, 'to_status' => OrderStatus::TO_SHIP, 'operator_type' => OrderStatus::OP_SYSTEM]);

        // 同一事件重放、渠道换个事件 ID 再推一次，都不会重复处理
        $this->notify($this->refundEvent())->assertStatus(200);
        $this->notify($this->refundEvent(['event_id' => 'evt_refund_2']))->assertStatus(200);
        $this->assertRefundDone();
        $this->assertSame(PayNotifyLog::OK, (int) PayNotifyLog::query()->where('event_id', 'evt_refund_2')->value('handle_status'));

        // 之后管理员再点“执行退款”会被拒绝，不会再退一次
        $this->payRefund()->assertJsonPath('code', 1);
        $this->assertSame(1, $this->channel->refundCalls);
    }

    public function test_a_callback_with_the_wrong_amount_or_for_an_unexpected_state_is_flagged_not_applied(): void
    {
        // 还没点过“执行退款”，渠道却来通知金额不对的退款成功
        $this->notify($this->refundEvent(['event_id' => 'evt_bad', 'amount' => '150.00']))->assertStatus(200);
        $this->assertSame(OrderRefund::REFUNDING, (int) $this->refund->fresh()->status);
        $log = PayNotifyLog::query()->where('event_id', 'evt_bad')->first();
        $this->assertSame(PayNotifyLog::FAIL, (int) $log->handle_status);
        $this->assertStringContainsString('不一致', (string) $log->error_msg);

        // 币种不对
        $this->notify($this->refundEvent(['event_id' => 'evt_cur', 'currency' => 'USD']))->assertStatus(200);
        $this->assertSame(OrderRefund::REFUNDING, (int) $this->refund->fresh()->status);

        // 不认识的售后单号、没带单号的退款（在渠道后台手工退的）：只记录
        $this->notify($this->refundEvent(['event_id' => 'evt_unknown', 'refund_no' => 'RF_NOPE']))->assertStatus(200);
        $this->assertSame(PayNotifyLog::FAIL, (int) PayNotifyLog::query()->where('event_id', 'evt_unknown')->value('handle_status'));
        $this->notify($this->refundEvent(['event_id' => 'evt_manual', 'refund_no' => '']))->assertStatus(200);
        $this->assertSame(PayNotifyLog::OK, (int) PayNotifyLog::query()->where('event_id', 'evt_manual')->value('handle_status'));

        $this->assertSame(8, $this->stock($this->sku));
        $this->assertSame(0, PayTrade::query()->where('trade_type', 2)->count());
    }

    public function test_a_failed_refund_can_be_started_again(): void
    {
        $this->payRefund()->assertJsonPath('data.done', false);

        // 渠道通知退款失败：售后单留在“退款中”，订单日志里有提示
        $this->notify($this->refundEvent(['event_id' => 'evt_fail', 'status' => 'refund_failed']))->assertStatus(200);
        $this->assertSame([OrderRefund::REFUNDING, ''], [(int) $this->refund->fresh()->status, (string) $this->refund->fresh()->refund_trade_no]);
        $this->assertTrue(DB::table('order_status_log')->where('order_id', $this->order->id)->where('remark', 'like', '%退款失败%')->exists());

        // 再点“执行退款”是重新发起，而不是查询那笔失败的
        $this->channel->refund = 'success';
        $this->payRefund()->assertJsonPath('data.done', true);
        $this->assertSame([2, 0], [$this->channel->refundCalls, $this->channel->queryCalls]);
        $this->assertRefundDone();
    }

    public function test_query_reporting_failure_clears_the_channel_refund_so_the_next_click_resends(): void
    {
        $this->payRefund()->assertJsonPath('data.done', false);
        $this->channel->query = 'failed';

        $this->payRefund()->assertJsonPath('code', 1);
        $this->assertSame('', (string) $this->refund->fresh()->refund_trade_no);

        $this->channel->refund = 'success';
        $this->payRefund()->assertJsonPath('data.done', true);
        $this->assertSame(2, $this->channel->refundCalls);
    }

    public function test_the_compensation_command_finishes_refunds_whose_callback_never_arrived(): void
    {
        $this->payRefund()->assertJsonPath('data.done', false);

        // 刚受理的先不查（给回调留时间）
        $this->channel->query = 'success';
        Artisan::call('mall:refund-compensate');
        $this->assertSame([0, OrderRefund::REFUNDING], [$this->channel->queryCalls, (int) $this->refund->fresh()->status]);

        // 过了几分钟还没结果：主动查询并完成
        OrderRefund::query()->whereKey($this->refund->id)->update(['updated_at' => now()->subMinutes(5)]);
        Artisan::call('mall:refund-compensate');
        $this->assertSame(1, $this->channel->queryCalls);
        $this->assertRefundDone();

        // 再跑一次没有可处理的
        Artisan::call('mall:refund-compensate');
        $this->assertSame(1, $this->channel->queryCalls);
    }

    public function test_compensation_skips_refunds_that_were_never_sent_to_the_channel(): void
    {
        // 审核通过但还没点“执行退款”的售后单，补偿任务不会替管理员发起退款
        OrderRefund::query()->whereKey($this->refund->id)->update(['updated_at' => now()->subHour()]);

        Artisan::call('mall:refund-compensate');

        $this->assertSame([0, 0], [$this->channel->refundCalls, $this->channel->queryCalls]);
        $this->assertSame(OrderRefund::REFUNDING, (int) $this->refund->fresh()->status);
    }
}
