<?php

namespace Tests\Feature;

use App\Drivers\Pay\AlipayDriver;
use App\Models\DriverConfig;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\PayNotifyLog;
use App\Models\PayTrade;
use App\Support\OrderStatus;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * 支付宝（公钥模式）：跳转地址的参数与签名、异步通知验签、查单、退款。
 * 借用 tests/Fixtures/wechat 里仅供测试的两对密钥：merchant 扮演应用，platform 扮演支付宝。
 * 能验证签名和验签的做法自洽，但不能代替用真实应用联调。
 */
class AlipayTest extends FeatureTestCase
{
    private const APP_ID = '2021000000000001';

    private string $token;

    private Order $order;

    private int $sku;

    protected function setUp(): void
    {
        parent::setUp();
        // 应用私钥按支付宝密钥工具的样子给：一长串 Base64，没有 PEM 头尾
        DriverConfig::query()->create(['type' => 'pay', 'driver' => 'alipay', 'name' => '支付宝', 'is_enabled' => 1, 'is_default' => 0, 'sort' => 0, 'config' => [
            'app_id' => self::APP_ID, 'private_key' => $this->bare('merchant_private'), 'alipay_public_key' => $this->bare('platform_public'),
            'gateway' => 'https://openapi.alipay.com/gateway.do', 'timeout' => '30', 'proxy' => '',
        ]]);

        [$m, $this->token] = $this->makeMember();
        $this->sku = $this->makeSku('99.90', 10);
        $this->order = $this->placeOrder($this->token, $this->sku, 2, (int) $this->makeAddress($m->id)->id);
    }

    private function key(string $name): string
    {
        return (string) file_get_contents(__DIR__."/../Fixtures/wechat/{$name}.pem");
    }

    /** 去掉 PEM 头尾和换行，只留 Base64 */
    private function bare(string $name): string
    {
        return preg_replace('/-----[A-Z ]+-----|\s+/', '', $this->key($name));
    }

    private function startPay(string $source, array $headers = [], ?Order $order = null)
    {
        $back = rtrim((string) config('app.url'), '/').'/h5/#/pages/order/detail?order_no='.($order ?? $this->order)->order_no;

        return $this->api('POST', '/orders/'.($order ?? $this->order)->order_no.'/pay', ['channel' => 'alipay', 'return_url' => $back], $this->token, ['X-Source' => $source] + $headers);
    }

    /** 模拟支付宝发来的异步通知：表单参数，用“支付宝”的私钥签名 */
    private function notify(array $params, string $signer = 'platform_private')
    {
        $params += ['notify_id' => 'N'.md5(json_encode($params)), 'notify_type' => 'trade_status_sync', 'app_id' => self::APP_ID, 'charset' => 'utf-8', 'version' => '1.0'];
        openssl_sign(AlipayDriver::content($params), $sig, $this->key($signer), OPENSSL_ALGO_SHA256);

        return $this->call('POST', '/notify/pay/alipay', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query($params + ['sign_type' => 'RSA2', 'sign' => base64_encode($sig)]));
    }

    private function paid(string $tradeNo, array $over = []): array
    {
        return $over + ['out_trade_no' => $tradeNo, 'trade_no' => '2026100922001400000000000001', 'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '199.80', 'subject' => '测试商品 等2件'];
    }

    /** @var array<string,array>|null 假网关当前的应答：接口名 => 业务应答；null 表示整个网关返回 error_response */
    private ?array $gatewayReplies = [];

    private array $gatewayError = [];

    private bool $gatewayFaked = false;

    /** 假网关：按接口名返回对应的业务应答，没列出的接口返回“交易不存在”。可以多次调用来更换应答 */
    private function gateway(?array $byMethod, array $error = []): void
    {
        [$this->gatewayReplies, $this->gatewayError] = [$byMethod, $error];
        if ($this->gatewayFaked) {
            return;
        }
        $this->gatewayFaked = true;
        Http::fake(['openapi.alipay.com/*' => function (HttpRequest $req) {
            if ($this->gatewayReplies === null) {
                return Http::response(['error_response' => $this->gatewayError]);
            }
            $method = $req['method'];

            return Http::response([str_replace('.', '_', $method).'_response' => $this->gatewayReplies[$method] ?? ['code' => '40004', 'msg' => 'Business Failed', 'sub_code' => 'ACQ.TRADE_NOT_EXIST', 'sub_msg' => '交易不存在'], 'sign' => 'x']);
        }]);
    }

    public function test_h5_and_pc_payments_redirect_to_the_gateway_with_a_valid_signature(): void
    {
        foreach (['h5' => ['alipay.trade.wap.pay', 'QUICK_WAP_WAY'], 'pc' => ['alipay.trade.page.pay', 'FAST_INSTANT_TRADE_PAY']] as $source => [$method, $product]) {
            $r = $this->startPay($source);
            $r->assertJsonPath('code', 0);
            $url = $r->json('data.redirect_url');
            $this->assertStringStartsWith('https://openapi.alipay.com/gateway.do?', $url);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $p);

            $this->assertSame([self::APP_ID, $method, 'RSA2', 'utf-8'], [$p['app_id'], $p['method'], $p['sign_type'], $p['charset']]);
            $this->assertStringEndsWith('/notify/pay/alipay', $p['notify_url']);
            $biz = json_decode($p['biz_content'], true);
            $this->assertSame([$r->json('data.trade_no'), '199.80', $product], [$biz['out_trade_no'], $biz['total_amount'], $biz['product_code']]);
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $p['timestamp']);

            // 用应用公钥验证：签的是除 sign 以外所有参数排序拼接后的内容
            $sign = base64_decode($p['sign']);
            unset($p['sign']);
            $this->assertSame(1, openssl_verify(AlipayDriver::content($p), $sign, $this->key('merchant_public'), OPENSSL_ALGO_SHA256), "{$source} 的签名验证不通过");
        }
        // 发起支付不需要请求支付宝，也不会把私钥带出去
        Http::assertNothingSent();
    }

    public function test_alipay_is_only_offered_where_it_can_work(): void
    {
        $channels = fn (string $source, ?Order $o = null) => array_column($this->api('GET', '/orders/'.($o ?? $this->order)->order_no.'/pay/channels', [], $this->token, ['X-Source' => $source])->json('data'), 'channel');

        $this->assertSame(['alipay'], $channels('h5'));
        $this->assertSame(['alipay'], $channels('pc'));
        $this->assertSame([], $channels('mp'), '小程序里不能用支付宝');
        $this->startPay('mp')->assertJsonPath('code', 1);

        $usd = $this->placeOrder($this->token, $this->sku, 1, (int) DB::table('member_address')->value('id'), [], ['X-Currency' => 'USD']);
        $this->assertSame([], $channels('h5', $usd));

        // 微信里打开的页面跳不到支付宝
        $this->startPay('h5', ['User-Agent' => 'Mozilla/5.0 (iPhone) MicroMessenger/8.0.50'])->assertJsonPath('code', 1);
    }

    public function test_a_genuine_notification_pays_the_order_and_forged_ones_are_rejected(): void
    {
        $tradeNo = $this->startPay('h5')->json('data.trade_no');
        $unpaid = fn () => $this->assertSame(OrderStatus::PAY_UNPAID, (int) $this->order->fresh()->pay_status);

        // 不是支付宝的私钥签的、别的应用的通知：拒收，应答 failure
        $forged = $this->notify($this->paid($tradeNo), 'merchant_private');
        $forged->assertStatus(400);
        $this->assertSame('failure', $forged->getContent());
        $this->notify($this->paid($tradeNo, ['app_id' => '2021999999999999']))->assertStatus(400);
        $unpaid();

        // 还没付款的中间状态：只记录
        $this->notify($this->paid($tradeNo, ['trade_status' => 'WAIT_BUYER_PAY']))->assertStatus(200);
        $unpaid();

        // 签名没问题但金额不对：不入账、标记异常
        $this->notify($this->paid($tradeNo, ['total_amount' => '1.00', 'notify_id' => 'N-amount']))->assertStatus(200);
        $unpaid();
        $this->assertSame(PayNotifyLog::FAIL, (int) PayNotifyLog::query()->where('event_id', 'N-amount')->value('handle_status'));

        // 真正的支付成功通知：必须应答 success，否则支付宝会一直重发
        $ok = $this->notify($this->paid($tradeNo));
        $ok->assertStatus(200);
        $this->assertSame('success', $ok->getContent());
        $order = $this->order->fresh();
        $this->assertSame([OrderStatus::TO_SHIP, OrderStatus::PAY_PAID, 'alipay'], [(int) $order->status, (int) $order->pay_status, $order->pay_channel]);
        $this->assertSame('2026100922001400000000000001', PayTrade::query()->where('trade_no', $tradeNo)->value('channel_trade_no'));

        // 重复通知不会重复处理
        $this->assertSame('success', $this->notify($this->paid($tradeNo))->getContent());
        $this->assertSame(2, (int) DB::table('goods_sku')->where('id', $this->sku)->value('sales'));
    }

    public function test_buyer_returning_from_alipay_is_confirmed_by_querying_the_trade(): void
    {
        $tradeNo = $this->startPay('h5')->json('data.trade_no');

        // 买家还没登录支付宝：支付宝侧没有这笔交易
        $this->gateway([]);
        $this->api('GET', "/pay/{$tradeNo}/confirm", [], $this->token)->assertJsonPath('data.status', 'pending');

        $this->gateway(['alipay.trade.query' => ['code' => '10000', 'msg' => 'Success', 'out_trade_no' => $tradeNo, 'trade_no' => '2026100922001400000000000002', 'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '199.80']]);
        $this->api('GET', "/pay/{$tradeNo}/confirm", [], $this->token)->assertJsonPath('data.status', 'success');

        $this->assertSame(OrderStatus::PAY_PAID, (int) $this->order->fresh()->pay_status);
        Http::assertSent(function (HttpRequest $req) use ($tradeNo) {
            $p = $req->data();
            $sign = base64_decode($p['sign']);
            unset($p['sign']);

            return $p['method'] === 'alipay.trade.query' && json_decode($p['biz_content'], true) === ['out_trade_no' => $tradeNo]
                && openssl_verify(AlipayDriver::content($p), $sign, $this->key('merchant_public'), OPENSSL_ALGO_SHA256) === 1;
        });
    }

    public function test_refund_is_immediate_and_refund_notifications_do_not_disturb_the_order(): void
    {
        $tradeNo = $this->startPay('h5')->json('data.trade_no');
        $this->notify($this->paid($tradeNo))->assertStatus(200);
        $itemId = (int) DB::table('order_item')->where('order_id', $this->order->id)->value('id');
        $refund = OrderRefund::query()->findOrFail($this->adminApi('POST', "/order/list/{$this->order->id}/refund", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '协商退款'])->json('data.id'));

        $this->gateway(['alipay.trade.refund' => ['code' => '10000', 'msg' => 'Success', 'trade_no' => '2026100922001400000000000001', 'out_trade_no' => $tradeNo, 'fund_change' => 'Y', 'refund_fee' => '99.90']]);
        $this->adminApi('POST', "/order/refund/{$refund->id}/pay", ['offline' => 0])->assertJsonPath('data.done', true);

        Http::assertSent(fn (HttpRequest $req) => $req['method'] === 'alipay.trade.refund'
            && json_decode($req['biz_content'], true) === ['out_trade_no' => $tradeNo, 'refund_amount' => '99.90', 'refund_reason' => '商家发起：协商退款', 'out_request_no' => $refund->refund_no]);
        $this->assertSame(OrderRefund::DONE, (int) $refund->fresh()->status);
        $this->assertSame(OrderStatus::PAY_PART_REFUND, (int) $this->order->fresh()->pay_status);

        // 退款后支付宝还会发通知（部分退款仍是 TRADE_SUCCESS，全额退款是 TRADE_CLOSED），都只记录，订单不受影响
        $this->notify($this->paid($tradeNo, ['refund_fee' => '99.90', 'out_biz_no' => $refund->refund_no, 'gmt_refund' => '2026-10-09 20:00:00']))->assertStatus(200);
        $this->notify($this->paid($tradeNo, ['trade_status' => 'TRADE_CLOSED', 'refund_fee' => '199.80', 'out_biz_no' => 'RF-OTHER']))->assertStatus(200);
        $order = $this->order->fresh();
        $this->assertSame([OrderStatus::TO_SHIP, OrderStatus::PAY_PART_REFUND], [(int) $order->status, (int) $order->pay_status]);
        $this->assertSame(1, PayTrade::query()->where('order_id', $order->id)->where('trade_type', 2)->count());
    }

    public function test_a_refund_the_gateway_rejects_stays_open_with_the_reason_shown_to_the_admin(): void
    {
        $tradeNo = $this->startPay('h5')->json('data.trade_no');
        $this->notify($this->paid($tradeNo))->assertStatus(200);
        $refund = OrderRefund::query()->findOrFail($this->adminApi('POST', "/order/list/{$this->order->id}/refund", ['type' => 1, 'reason' => '全退'])->json('data.id'));

        $this->gateway(['alipay.trade.refund' => ['code' => '40004', 'msg' => 'Business Failed', 'sub_code' => 'ACQ.SELLER_BALANCE_NOT_ENOUGH', 'sub_msg' => '卖家余额不足']]);
        $r = $this->adminApi('POST', "/order/refund/{$refund->id}/pay", ['offline' => 0]);

        $r->assertJsonPath('code', 1);
        $this->assertStringContainsString('卖家余额不足', $r->json('msg'));
        $this->assertSame(OrderRefund::REFUNDING, (int) $refund->fresh()->status);
    }

    public function test_admin_can_test_the_connection_and_the_private_key_is_never_shown(): void
    {
        $list = $this->adminApi('GET', '/driver/pay');
        $alipay = collect($list->json('data'))->firstWhere('driver', 'alipay');
        $this->assertSame('******', $alipay['config']['private_key']);
        $this->assertStringNotContainsString($this->bare('merchant_private'), $list->getContent());

        $this->gateway([]); // 查一笔不存在的交易，返回“交易不存在”说明应用和私钥都对
        $this->adminApi('POST', '/driver/pay/alipay/test')->assertJsonPath('code', 0);

        $this->gateway(null, ['code' => '40002', 'msg' => 'Invalid Arguments', 'sub_code' => 'isv.invalid-signature', 'sub_msg' => '验签出错，建议检查签名字符串或签名私钥与应用公钥是否匹配']);
        $bad = $this->adminApi('POST', '/driver/pay/alipay/test');
        $bad->assertJsonPath('code', 1);
        $this->assertStringContainsString('验签出错', $bad->json('msg'));
    }
}
