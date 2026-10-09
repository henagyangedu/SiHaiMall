<?php

namespace Tests\Feature;

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
 * 微信支付（API v3）：下单参数与签名、小程序调起参数、H5 跳转、回调验签与解密、退款。
 * 用 tests/Fixtures/wechat 里仅供测试的两对密钥分别扮演商户和微信：能验证签名、验签、加解密的做法自洽，
 * 但不能代替用真实商户号联调。
 */
class WechatPayTest extends FeatureTestCase
{
    private const V3_KEY = '0123456789abcdef0123456789abcdef';

    private const KEY_ID = 'PUB_KEY_ID_0000000000000000000000000001';

    private string $token;

    private Order $order;

    private int $sku;

    protected function setUp(): void
    {
        parent::setUp();
        DriverConfig::query()->create(['type' => 'pay', 'driver' => 'wechat', 'name' => '微信支付', 'is_enabled' => 1, 'is_default' => 0, 'sort' => 0, 'config' => [
            'mch_id' => '1900000001', 'api_v3_key' => self::V3_KEY, 'serial_no' => 'MERCHANTSERIAL01', 'private_key' => $this->key('merchant_private'),
            'public_key_id' => self::KEY_ID, 'public_key' => $this->key('platform_public'),
            'mp_app_id' => 'wxmp0000000000001', 'mp_app_secret' => 'mp-secret', 'h5_app_id' => '',
            'api_base' => 'https://api.mch.weixin.qq.com', 'timeout' => '30', 'proxy' => '',
        ]]);

        [$m, $this->token] = $this->makeMember();
        $this->sku = $this->makeSku('99.90', 10);
        $this->order = $this->placeOrder($this->token, $this->sku, 2, (int) $this->makeAddress($m->id)->id);
    }

    private function key(string $name): string
    {
        return (string) file_get_contents(__DIR__."/../Fixtures/wechat/{$name}.pem");
    }

    private function startPay(string $source, array $data = [], array $headers = [], ?Order $order = null)
    {
        return $this->api('POST', '/orders/'.($order ?? $this->order)->order_no.'/pay', ['channel' => 'wechat'] + $data, $this->token, ['X-Source' => $source] + $headers);
    }

    /** 用商户公钥验证请求头里的签名确实是对“方法、路径、时间戳、随机串、报文”签的 */
    private function assertSignedByMerchant(HttpRequest $req, string $method, string $path): void
    {
        $auth = $req->header('Authorization')[0] ?? '';
        $this->assertStringStartsWith('WECHATPAY2-SHA256-RSA2048 ', $auth);
        preg_match_all('/(\w+)="([^"]*)"/', $auth, $m);
        $p = array_combine($m[1], $m[2]);
        $this->assertSame(['1900000001', 'MERCHANTSERIAL01'], [$p['mchid'], $p['serial_no']]);
        $message = "{$method}\n{$path}\n{$p['timestamp']}\n{$p['nonce_str']}\n{$req->body()}\n";
        $this->assertSame(1, openssl_verify($message, base64_decode($p['signature']), $this->key('merchant_public'), OPENSSL_ALGO_SHA256), '请求签名验证不通过');
        $this->assertSame(self::KEY_ID, $req->header('Wechatpay-Serial')[0] ?? '');
    }

    /** 模拟微信发来的回调：用 APIv3 密钥加密内容、用“微信支付”的私钥签名 */
    private function notify(string $eventType, array $resource, array $over = [])
    {
        $nonce = substr(md5($eventType.json_encode($resource)), 0, 12);
        $cipher = openssl_encrypt(json_encode($resource), 'aes-256-gcm', $over['v3_key'] ?? self::V3_KEY, OPENSSL_RAW_DATA, $nonce, $tag, 'transaction');
        $body = json_encode(['id' => $over['id'] ?? 'EV-'.md5($eventType.json_encode($resource)), 'event_type' => $eventType, 'resource_type' => 'encrypt-resource',
            'resource' => ['algorithm' => 'AEAD_AES_256_GCM', 'ciphertext' => base64_encode($cipher.$tag), 'nonce' => $nonce, 'associated_data' => 'transaction']]);
        $ts = (string) ($over['timestamp'] ?? time());
        openssl_sign("{$ts}\nNONCE123\n{$body}\n", $sig, $this->key($over['signer'] ?? 'platform_private'), OPENSSL_ALGO_SHA256);

        return $this->call('POST', '/notify/pay/wechat', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_WECHATPAY_TIMESTAMP' => $ts, 'HTTP_WECHATPAY_NONCE' => 'NONCE123',
            'HTTP_WECHATPAY_SIGNATURE' => base64_encode($sig), 'HTTP_WECHATPAY_SERIAL' => $over['serial'] ?? self::KEY_ID,
        ], $over['body'] ?? $body);
    }

    private function paidEvent(string $tradeNo, array $over = []): array
    {
        return $over + ['mchid' => '1900000001', 'out_trade_no' => $tradeNo, 'transaction_id' => '4200000000000000001', 'trade_state' => 'SUCCESS',
            'amount' => ['total' => 19980, 'payer_total' => 19980, 'currency' => 'CNY']];
    }

    public function test_mini_program_payment_creates_a_jsapi_order_and_returns_signed_client_params(): void
    {
        Http::fake([
            'api.weixin.qq.com/sns/jscode2session*' => Http::response(['openid' => 'oUser0001', 'session_key' => 'x']),
            'api.mch.weixin.qq.com/v3/pay/transactions/jsapi' => Http::response(['prepay_id' => 'wx201410272009395522657a690389285100']),
        ]);

        $r = $this->startPay('mp', ['wx_code' => 'CODE-FROM-WX-LOGIN']);

        $r->assertJsonPath('code', 0);
        $this->assertSame('', $r->json('data.redirect_url'));
        $p = $r->json('data.client_params');
        $this->assertSame(['prepay_id=wx201410272009395522657a690389285100', 'RSA'], [$p['package'], $p['signType']]);
        // 小程序调起参数的签名：appId、时间戳、随机串、package 各占一行
        $this->assertSame(1, openssl_verify("wxmp0000000000001\n{$p['timeStamp']}\n{$p['nonceStr']}\n{$p['package']}\n", base64_decode($p['paySign']), $this->key('merchant_public'), OPENSSL_ALGO_SHA256));

        $tradeNo = $r->json('data.trade_no');
        Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), 'jscode2session') && $req['js_code'] === 'CODE-FROM-WX-LOGIN' && $req['appid'] === 'wxmp0000000000001' && $req['secret'] === 'mp-secret');
        Http::assertSent(function (HttpRequest $req) use ($tradeNo) {
            if (! str_ends_with($req->url(), '/v3/pay/transactions/jsapi')) {
                return false;
            }
            $this->assertSignedByMerchant($req, 'POST', '/v3/pay/transactions/jsapi');
            $b = json_decode($req->body(), true);
            $this->assertSame(['wxmp0000000000001', '1900000001', $tradeNo, ['total' => 19980, 'currency' => 'CNY'], ['openid' => 'oUser0001']],
                [$b['appid'], $b['mchid'], $b['out_trade_no'], $b['amount'], $b['payer']]);
            $this->assertStringEndsWith('/notify/pay/wechat', $b['notify_url']);
            $this->assertNotSame('', $b['description']);

            return true;
        });
        // 密钥不会出现在返回给买家的内容里
        $this->assertStringNotContainsString('mp-secret', $r->getContent());
        $this->assertStringNotContainsString('oUser0001', $r->getContent());
    }

    public function test_mini_program_payment_needs_a_valid_login_code(): void
    {
        Http::fake(['api.weixin.qq.com/*' => Http::response(['errcode' => 40029, 'errmsg' => 'invalid code'])]);

        $this->startPay('mp')->assertJsonPath('code', 1);
        $this->startPay('mp', ['wx_code' => 'EXPIRED'])->assertJsonPath('code', 1);

        Http::assertNotSent(fn (HttpRequest $req) => str_contains($req->url(), 'api.mch.weixin.qq.com'));
        $this->assertSame(0, PayTrade::query()->where('status', PayTrade::PENDING)->count(), '失败的支付单不会留在待支付');
    }

    public function test_h5_payment_redirects_to_wechat_and_is_refused_inside_the_wechat_browser(): void
    {
        Http::fake(['api.mch.weixin.qq.com/v3/pay/transactions/h5' => Http::response(['h5_url' => 'https://wx.tenpay.com/cgi-bin/mmpayweb-bin/checkmweb?prepay_id=wx1&package=2'])]);
        $back = rtrim((string) config('app.url'), '/').'/h5/#/pages/order/detail?order_no='.$this->order->order_no;

        $inWechat = $this->startPay('h5', ['return_url' => $back], ['User-Agent' => 'Mozilla/5.0 (iPhone) MicroMessenger/8.0.50']);
        $inWechat->assertJsonPath('code', 1);
        Http::assertNothingSent();

        $r = $this->startPay('h5', ['return_url' => $back], ['User-Agent' => 'Mozilla/5.0 (iPhone) Safari/604.1']);
        $r->assertJsonPath('code', 0);
        $url = $r->json('data.redirect_url');
        $this->assertStringStartsWith('https://wx.tenpay.com/cgi-bin/mmpayweb-bin/checkmweb?prepay_id=wx1&package=2&redirect_url=', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame($back.'&trade_no='.$r->json('data.trade_no'), $q['redirect_url']);
        Http::assertSent(function (HttpRequest $req) {
            $b = json_decode($req->body(), true);

            return $b['appid'] === 'wxmp0000000000001' && $b['scene_info']['h5_info']['type'] === 'Wap' && $b['scene_info']['payer_client_ip'] !== '';
        });

        // 再点一次会重新下单，不复用上一次的跳转地址（微信的 H5 支付链接几分钟就失效）
        $again = $this->startPay('h5', ['return_url' => $back]);
        $this->assertNotSame($r->json('data.trade_no'), $again->json('data.trade_no'));
    }

    public function test_wechat_pay_is_only_offered_where_it_can_work(): void
    {
        $channels = fn (string $source, ?Order $o = null) => array_column($this->api('GET', '/orders/'.($o ?? $this->order)->order_no.'/pay/channels', [], $this->token, ['X-Source' => $source])->json('data'), 'channel');

        $this->assertSame(['wechat'], $channels('mp'));
        $this->assertSame(['wechat'], $channels('h5'));
        $this->assertSame([], $channels('pc'));
        $this->assertSame([], $channels('app'));

        // 美元订单不能用微信支付
        $usd = $this->placeOrder($this->token, $this->sku, 1, (int) DB::table('member_address')->value('id'), [], ['X-Currency' => 'USD']);
        $this->assertSame([], $channels('h5', $usd));
        $this->startPay('h5', [], [], $usd)->assertJsonPath('code', 1);

        // 没配小程序 AppID 时小程序里不显示
        $row = DriverConfig::query()->where('driver', 'wechat')->first();
        $row->update(['config' => ['mp_app_id' => ''] + $row->config]);
        $this->assertSame([], $channels('mp'));
    }

    public function test_a_genuine_payment_callback_pays_the_order_and_forged_ones_are_rejected(): void
    {
        Http::fake(['api.weixin.qq.com/*' => Http::response(['openid' => 'oUser0001']), 'api.mch.weixin.qq.com/*' => Http::response(['prepay_id' => 'wx1'])]);
        $tradeNo = $this->startPay('mp', ['wx_code' => 'C'])->json('data.trade_no');
        $unpaid = fn () => $this->assertSame(OrderStatus::PAY_UNPAID, (int) $this->order->fresh()->pay_status);

        // 不是微信的私钥签的、签名对不上报文、时间戳太旧、公钥 ID 不对：一律拒收
        $this->notify('TRANSACTION.SUCCESS', $this->paidEvent($tradeNo), ['signer' => 'merchant_private'])->assertStatus(400);
        $this->notify('TRANSACTION.SUCCESS', $this->paidEvent($tradeNo), ['body' => '{"id":"EV-x","event_type":"TRANSACTION.SUCCESS"}'])->assertStatus(400);
        $this->notify('TRANSACTION.SUCCESS', $this->paidEvent($tradeNo), ['timestamp' => time() - 3600])->assertStatus(400);
        $this->notify('TRANSACTION.SUCCESS', $this->paidEvent($tradeNo), ['serial' => 'PUB_KEY_ID_OTHER'])->assertStatus(400);
        $unpaid();

        // 签名没问题但金额不对：收下回调、不入账、标记异常
        $this->notify('TRANSACTION.SUCCESS', $this->paidEvent($tradeNo, ['amount' => ['total' => 100, 'currency' => 'CNY']]), ['id' => 'EV-amount'])->assertStatus(200);
        $unpaid();
        $this->assertSame(PayNotifyLog::FAIL, (int) PayNotifyLog::query()->where('event_id', 'EV-amount')->value('handle_status'));

        // 真正的支付成功回调
        $this->notify('TRANSACTION.SUCCESS', $this->paidEvent($tradeNo))->assertStatus(200);
        $order = $this->order->fresh();
        $this->assertSame([OrderStatus::TO_SHIP, OrderStatus::PAY_PAID, 'wechat'], [(int) $order->status, (int) $order->pay_status, $order->pay_channel]);
        $this->assertSame('4200000000000000001', PayTrade::query()->where('trade_no', $tradeNo)->value('channel_trade_no'));

        // 重复推送不会重复处理
        $this->notify('TRANSACTION.SUCCESS', $this->paidEvent($tradeNo))->assertStatus(200);
        $this->assertSame(2, (int) DB::table('goods_sku')->where('id', $this->sku)->value('sales'));
    }

    public function test_a_callback_that_cannot_be_decrypted_is_answered_with_an_error_so_wechat_retries(): void
    {
        // APIv3 密钥配错时：验签能过但解不开内容，应答 500 让微信稍后重试，而不是悄悄丢掉
        $this->notify('TRANSACTION.SUCCESS', $this->paidEvent('PT1'), ['v3_key' => 'ffffffffffffffffffffffffffffffff'])->assertStatus(500);
    }

    public function test_buyer_returning_from_wechat_is_confirmed_by_querying_the_order(): void
    {
        Http::fake(['api.weixin.qq.com/*' => Http::response(['openid' => 'oUser0001']), 'api.mch.weixin.qq.com/v3/pay/transactions/jsapi' => Http::response(['prepay_id' => 'wx1'])]);
        $tradeNo = $this->startPay('mp', ['wx_code' => 'C'])->json('data.trade_no');

        Http::fake(["api.mch.weixin.qq.com/v3/pay/transactions/out-trade-no/{$tradeNo}*" => Http::sequence()
            ->push(['trade_state' => 'NOTPAY', 'out_trade_no' => $tradeNo])
            ->push(['trade_state' => 'SUCCESS', 'out_trade_no' => $tradeNo, 'transaction_id' => '4200000000000000002', 'amount' => ['total' => 19980, 'currency' => 'CNY']])]);

        $this->api('GET', "/pay/{$tradeNo}/confirm", [], $this->token)->assertJsonPath('data.status', 'pending');
        $this->api('GET', "/pay/{$tradeNo}/confirm", [], $this->token)->assertJsonPath('data.status', 'success');

        $this->assertSame(OrderStatus::PAY_PAID, (int) $this->order->fresh()->pay_status);
        Http::assertSent(function (HttpRequest $req) use ($tradeNo) {
            if (! str_contains($req->url(), '/out-trade-no/')) {
                return false;
            }
            $this->assertSignedByMerchant($req, 'GET', "/v3/pay/transactions/out-trade-no/{$tradeNo}?mchid=1900000001");

            return true;
        });
    }

    public function test_refund_goes_back_through_wechat_and_the_refund_callback_completes_it(): void
    {
        Http::fake(['api.weixin.qq.com/*' => Http::response(['openid' => 'oUser0001']), 'api.mch.weixin.qq.com/v3/pay/transactions/jsapi' => Http::response(['prepay_id' => 'wx1'])]);
        $tradeNo = $this->startPay('mp', ['wx_code' => 'C'])->json('data.trade_no');
        $this->notify('TRANSACTION.SUCCESS', $this->paidEvent($tradeNo))->assertStatus(200);

        $itemId = (int) DB::table('order_item')->where('order_id', $this->order->id)->value('id');
        $refund = OrderRefund::query()->findOrFail($this->adminApi('POST', "/order/list/{$this->order->id}/refund", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '协商退款'])->json('data.id'));

        // 微信受理了退款，但还在处理中
        Http::fake(['api.mch.weixin.qq.com/v3/refund/domestic/refunds' => Http::response(['refund_id' => '50000000382019052709732678859', 'out_refund_no' => $refund->refund_no, 'status' => 'PROCESSING'])]);
        $this->adminApi('POST', "/order/refund/{$refund->id}/pay", ['offline' => 0])->assertJsonPath('data.done', false);
        Http::assertSent(function (HttpRequest $req) use ($tradeNo, $refund) {
            if (! str_ends_with($req->url(), '/v3/refund/domestic/refunds')) {
                return false;
            }
            $b = json_decode($req->body(), true);
            $this->assertSame([$tradeNo, $refund->refund_no, ['refund' => 9990, 'total' => 19980, 'currency' => 'CNY']], [$b['out_trade_no'], $b['out_refund_no'], $b['amount']]);

            return true;
        });

        // 退款成功的回调到了：售后单自动完成
        $this->notify('REFUND.SUCCESS', ['out_trade_no' => $tradeNo, 'out_refund_no' => $refund->refund_no, 'refund_id' => '50000000382019052709732678859',
            'refund_status' => 'SUCCESS', 'amount' => ['total' => 19980, 'refund' => 9990, 'payer_total' => 19980, 'payer_refund' => 9990]])->assertStatus(200);

        $this->assertSame(OrderRefund::DONE, (int) $refund->fresh()->status);
        $order = $this->order->fresh();
        $this->assertSame([OrderStatus::TO_SHIP, OrderStatus::PAY_PART_REFUND], [(int) $order->status, (int) $order->pay_status]);
        $this->assertDatabaseHas('pay_trade', ['trade_no' => $refund->refund_no, 'trade_type' => 2, 'channel' => 'wechat', 'amount' => '99.90']);
    }

    public function test_admin_config_hides_the_private_key_and_can_test_the_connection(): void
    {
        $list = $this->adminApi('GET', '/driver/pay');
        $wechat = collect($list->json('data'))->firstWhere('driver', 'wechat');
        $this->assertSame(['******', '******', '******'], [$wechat['config']['private_key'], $wechat['config']['api_v3_key'], $wechat['config']['mp_app_secret']]);
        $this->assertStringNotContainsString('PRIVATE KEY', $list->getContent());
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $wechat['config']['public_key'], '公钥不是秘密，原样显示方便核对');

        // 原样提交掩码不会把私钥冲掉；私钥被压成一行粘贴进来也能用
        $oneLine = str_replace("\n", '\n', trim($this->key('merchant_private')));
        $this->adminApi('PUT', '/driver/pay/wechat', ['config' => ['private_key' => '******', 'mch_id' => '1900000001']])->assertJsonPath('code', 0);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', DriverConfig::query()->where('driver', 'wechat')->first()->config['private_key']);
        $this->adminApi('PUT', '/driver/pay/wechat', ['config' => ['private_key' => $oneLine]])->assertJsonPath('code', 0);

        // 测试连接：查一个不存在的支付单，微信回“订单不存在”说明商户号和签名都对
        Http::fake(['api.mch.weixin.qq.com/*' => Http::sequence()
            ->push(['code' => 'ORDER_NOT_EXIST', 'message' => '订单不存在'], 404)
            ->push(['code' => 'SIGN_ERROR', 'message' => '签名错误，请检查后再试'], 401)]);
        $this->adminApi('POST', '/driver/pay/wechat/test')->assertJsonPath('code', 0);
        $bad = $this->adminApi('POST', '/driver/pay/wechat/test');
        $bad->assertJsonPath('code', 1);
        $this->assertStringContainsString('签名错误', $bad->json('msg'));
    }
}
