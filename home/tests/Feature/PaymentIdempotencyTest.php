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
use App\Models\MemberToken;
use App\Models\Order;
use App\Models\PayNotifyLog;
use App\Models\PayTrade;
use App\Services\Common\OrderPaidService;
use App\Support\OrderStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\FeatureTestCase;

/** 支付入账的幂等：重复标记、回调重放、金额不符、订单已取消后到账、验签失败 */
class PaymentIdempotencyTest extends FeatureTestCase
{
    private Order $order;

    private PayTrade $trade;

    private int $sku;

    protected function setUp(): void
    {
        parent::setUp();

        // 假的支付渠道：回调报文就是一段 JSON，字段原样变成解析结果，verified 表示验签是否通过
        $fake = new class implements PayDriver
        {
            public function code(): string
            {
                return 'stripe';
            }

            public function create(PayRequest $req): PayResult
            {
                throw new \RuntimeException('测试里不会发起真实支付');
            }

            public function verifyNotify(Request $request): NotifyResult
            {
                return $this->parse($request->getContent());
            }

            public function parse(string $body): NotifyResult
            {
                $d = json_decode($body, true);

                return new NotifyResult(
                    verified: (bool) ($d['verified'] ?? true), eventId: (string) $d['event_id'], tradeNo: (string) $d['trade_no'],
                    channelTradeNo: (string) ($d['channel_trade_no'] ?? 'ch_1'), status: (string) ($d['status'] ?? 'success'),
                    currency: (string) ($d['currency'] ?? 'CNY'), amount: (string) $d['amount'], rawBody: $body,
                );
            }

            public function confirm(string $tradeNo, string $channelTradeNo): QueryResult
            {
                throw new \RuntimeException('未用到');
            }

            public function query(string $tradeNo, string $channelTradeNo): QueryResult
            {
                throw new \RuntimeException('未用到');
            }

            public function refund(RefundRequest $req): RefundResult
            {
                throw new \RuntimeException('未用到');
            }

            public function queryRefund(string $refundNo, string $channelRefundNo): RefundResult
            {
                throw new RuntimeException('未用到');
            }

            public function ping(): array
            {
                return [];
            }
        };
        $manager = Mockery::mock(DriverManager::class)->makePartial();
        $manager->shouldReceive('payForNotify')->andReturn($fake);
        $this->app->instance(DriverManager::class, $manager);

        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $this->sku = $this->makeSku('100.00', 10);
        $this->order = $this->placeOrder($token, $this->sku, 2, $addr->id);
        $this->trade = PayTrade::query()->create([
            'trade_no' => 'PT'.$this->order->order_no, 'order_id' => $this->order->id, 'member_id' => $m->id, 'channel' => 'stripe',
            'channel_trade_no' => '', 'trade_type' => 1, 'currency' => 'CNY', 'amount' => $this->order->pay_amount, 'status' => PayTrade::PENDING,
        ]);
    }

    private function notify(array $payload)
    {
        return $this->call('POST', '/notify/pay/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload + ['trade_no' => $this->trade->trade_no]));
    }

    private function sales(): int
    {
        return (int) DB::table('goods_sku')->where('id', $this->sku)->value('sales');
    }

    public function test_marking_paid_twice_only_takes_effect_once(): void
    {
        $paid = app(OrderPaidService::class);

        $this->assertTrue($paid->markPaid((int) $this->order->id, 'offline'));
        $this->assertFalse($paid->markPaid((int) $this->order->id, 'offline'), '第二次应返回 false，表示已处理过');

        $order = $this->order->fresh();
        $this->assertSame([OrderStatus::TO_SHIP, OrderStatus::PAY_PAID], [(int) $order->status, (int) $order->pay_status]);
        $this->assertSame(2, $this->sales(), '销量只累加一次');
        $this->assertSame(1, DB::table('order_status_log')->where('order_id', $order->id)->where('to_status', OrderStatus::TO_SHIP)->count());
    }

    public function test_a_success_callback_pays_the_order_and_a_replay_is_ignored(): void
    {
        $event = ['event_id' => 'evt_1', 'amount' => (string) $this->order->pay_amount];

        $this->notify($event)->assertStatus(200);
        $this->assertSame(OrderStatus::PAY_PAID, (int) $this->order->fresh()->pay_status);
        $this->assertSame(PayTrade::SUCCESS, (int) $this->trade->fresh()->status);

        // 渠道重复推送同一个事件：照常应答，但不再处理
        $this->notify($event)->assertStatus(200);
        $this->notify($event)->assertStatus(200);

        $this->assertSame(2, $this->sales());
        $this->assertSame(1, PayNotifyLog::query()->where('channel', 'stripe')->where('event_id', 'evt_1')->count());
        $this->assertSame(1, DB::table('order_status_log')->where('order_id', $this->order->id)->where('to_status', OrderStatus::TO_SHIP)->count());
    }

    public function test_a_second_event_for_an_already_paid_trade_does_not_pay_again(): void
    {
        $this->notify(['event_id' => 'evt_1', 'amount' => (string) $this->order->pay_amount])->assertStatus(200);
        // 同一笔支付，渠道换了个事件 ID 再推一次（例如异步到账事件）
        $this->notify(['event_id' => 'evt_2', 'amount' => (string) $this->order->pay_amount])->assertStatus(200);

        $this->assertSame(2, $this->sales());
        $this->assertSame(2, PayNotifyLog::query()->where('channel', 'stripe')->count());
    }

    public function test_amount_mismatch_leaves_the_order_unpaid_and_flags_the_trade(): void
    {
        $this->notify(['event_id' => 'evt_bad', 'amount' => '1.00'])->assertStatus(200);

        $this->assertSame(OrderStatus::PAY_UNPAID, (int) $this->order->fresh()->pay_status);
        $this->assertSame(PayTrade::PENDING, (int) $this->trade->fresh()->status);
        $this->assertStringContainsString('不一致', (string) ($this->trade->fresh()->rawData()['flag'] ?? ''));
        $this->assertSame(PayNotifyLog::FAIL, (int) PayNotifyLog::query()->where('event_id', 'evt_bad')->value('handle_status'));
        $this->assertSame(0, $this->sales());
    }

    public function test_currency_mismatch_is_rejected_too(): void
    {
        $this->notify(['event_id' => 'evt_cur', 'amount' => (string) $this->order->pay_amount, 'currency' => 'USD'])->assertStatus(200);

        $this->assertSame(OrderStatus::PAY_UNPAID, (int) $this->order->fresh()->pay_status);
    }

    public function test_money_arriving_for_a_cancelled_order_is_recorded_but_does_not_revive_the_order(): void
    {
        $this->api('POST', "/orders/{$this->order->order_no}/cancel", [], $this->memberToken())->assertJsonPath('code', 0);
        $this->assertSame(10, $this->stock($this->sku));

        $this->notify(['event_id' => 'evt_late', 'amount' => (string) $this->order->pay_amount])->assertStatus(200);

        $order = $this->order->fresh();
        $this->assertSame([OrderStatus::CANCELLED, OrderStatus::PAY_UNPAID], [(int) $order->status, (int) $order->pay_status]);
        // 钱确实收到了：支付单记为成功并打上“需人工退款”的标记，库存不会被再次扣减
        $trade = $this->trade->fresh();
        $this->assertSame(PayTrade::SUCCESS, (int) $trade->status);
        $this->assertStringContainsString('退款', (string) ($trade->rawData()['flag'] ?? ''));
        $this->assertSame(10, $this->stock($this->sku));
    }

    public function test_a_callback_that_fails_signature_verification_changes_nothing(): void
    {
        $this->notify(['event_id' => 'evt_forged', 'amount' => (string) $this->order->pay_amount, 'verified' => false])->assertStatus(400);

        $this->assertSame(OrderStatus::PAY_UNPAID, (int) $this->order->fresh()->pay_status);
        $this->assertSame(PayTrade::PENDING, (int) $this->trade->fresh()->status);
    }

    /** setUp 里创建的会员没有保留令牌，这里给他补发一个用于调接口 */
    private function memberToken(): string
    {
        $plain = Str::random(64);
        MemberToken::query()->create([
            'member_id' => $this->order->member_id, 'token_hash' => hash('sha256', $plain), 'platform' => 'h5',
            'expires_at' => now()->addDay(), 'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'test',
        ]);

        return $plain;
    }
}
