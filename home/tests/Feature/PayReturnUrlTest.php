<?php

namespace Tests\Feature;

use App\Models\DriverConfig;
use App\Models\Order;
use App\Models\PayTrade;
use Tests\FeatureTestCase;

/** 发起支付时对“付完跳回的地址”的校验：只允许跳回自己的站点，防止被人利用做钓鱼跳转 */
class PayReturnUrlTest extends FeatureTestCase
{
    private string $token;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://api.shop.test', 'mall.pay_return_hosts' => 'm.shop.test, 127.0.0.1']);
        // 支付宝发起支付只是生成跳转地址，不请求外部，适合用来测这个校验
        DriverConfig::query()->create(['type' => 'pay', 'driver' => 'alipay', 'name' => '支付宝', 'is_enabled' => 1, 'is_default' => 0, 'sort' => 0, 'config' => [
            'app_id' => '2021000000000001', 'private_key' => (string) file_get_contents(__DIR__.'/../Fixtures/wechat/merchant_private.pem'),
            'alipay_public_key' => (string) file_get_contents(__DIR__.'/../Fixtures/wechat/platform_public.pem'),
        ]]);
        [$m, $this->token] = $this->makeMember();
        $this->order = $this->placeOrder($this->token, $this->makeSku('10.00', 5), 1, (int) $this->makeAddress($m->id)->id);
    }

    private function startPay(?string $returnUrl)
    {
        return $this->api('POST', "/orders/{$this->order->order_no}/pay", ['channel' => 'alipay'] + ($returnUrl === null ? [] : ['return_url' => $returnUrl]), $this->token, ['X-Source' => 'h5']);
    }

    public function test_return_to_the_api_domain_or_a_whitelisted_frontend_is_allowed(): void
    {
        foreach ([
            'https://api.shop.test/h5/#/pages/order/detail?order_no=1',
            'https://m.shop.test/#/orders/GM1',
            // 本地开发：用 127.0.0.1 加端口访问前端，端口不参与判断
            'http://127.0.0.1:5190/#/orders/GM1',
            'http://127.0.0.1:5180/#/pages/order/detail?order_no=GM1',
        ] as $url) {
            $r = $this->startPay($url);
            $this->assertSame(0, $r->json('code'), "应该允许：{$url}（{$r->json('msg')}）");
            parse_str((string) parse_url($r->json('data.redirect_url'), PHP_URL_QUERY), $q);
            $this->assertSame($url, $q['return_url']);
        }

        // 不传回跳地址时用后端自己的默认页
        parse_str((string) parse_url($this->startPay(null)->json('data.redirect_url'), PHP_URL_QUERY), $q);
        $this->assertSame('https://api.shop.test/pay/return', $q['return_url']);
    }

    public function test_other_domains_and_non_http_addresses_are_rejected(): void
    {
        foreach ([
            'https://evil.example/collect',
            'https://m.shop.test.evil.example/',   // 只是以允许的域名开头
            'https://evil.example/?m.shop.test',   // 允许的域名出现在参数里
            'javascript:alert(1)',
            '//evil.example/x',
            'ftp://m.shop.test/x',
        ] as $url) {
            $r = $this->startPay($url);
            $this->assertSame(1, $r->json('code'), "应该拒绝：{$url}");
        }
        $this->assertSame(0, PayTrade::query()->count(), '被拒绝的请求不会留下支付单');
    }

    public function test_without_the_whitelist_only_the_api_domain_is_allowed(): void
    {
        config(['mall.pay_return_hosts' => '']);

        $this->startPay('https://api.shop.test/x')->assertJsonPath('code', 0);
        $this->startPay('http://127.0.0.1:5190/#/orders/GM1')->assertJsonPath('code', 1);
    }
}
