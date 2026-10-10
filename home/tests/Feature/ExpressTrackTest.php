<?php

namespace Tests\Feature;

use App\Drivers\Contracts\ExpressDriver;
use App\Drivers\DriverManager;
use App\Drivers\Express\Kuaidi100Driver;
use App\Models\DriverConfig;
use App\Models\Order;
use App\Models\Shipping;
use App\Support\Exceptions\BusinessException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\FeatureTestCase;

/** 物流轨迹查询：缓存、查询频率、各种查不到的情况，以及快递100 驱动的签名和结果解析 */
class ExpressTrackTest extends FeatureTestCase
{
    /** 假服务商的状态：下次查询返回什么、被查了几次、最后一次查询的参数 */
    private object $carrier;

    private string $token;

    private Order $order;

    private Shipping $shipping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->carrier = (object) ['result' => ['status' => 1, 'traces' => [['time' => '2026-10-09 10:00:00', 'context' => '快件已揽收']]], 'error' => null, 'calls' => 0, 'last' => null];
        $fake = new class($this->carrier) implements ExpressDriver
        {
            public function __construct(private object $state) {}

            public function code(): string
            {
                return 'fake';
            }

            public function supports(string $expressCode): bool
            {
                return $expressCode !== 'OTHER';
            }

            public function query(string $expressCode, string $expressNo, string $phone = ''): array
            {
                $this->state->calls++;
                $this->state->last = [$expressCode, $expressNo, $phone];
                if ($this->state->error) {
                    throw new BusinessException($this->state->error);
                }

                return $this->state->result;
            }

            public function ping(): array
            {
                return [];
            }
        };
        $manager = Mockery::mock(DriverManager::class)->makePartial();
        $manager->shouldReceive('expressList')->andReturn([$fake]);
        $this->app->instance(DriverManager::class, $manager);

        [$m, $this->token] = $this->makeMember();
        $this->order = $this->pay($this->placeOrder($this->token, $this->makeSku('100.00', 10), 1, (int) $this->makeAddress($m->id, ['phone' => '13812345678'])->id));
        $this->adminApi('POST', "/order/ship/{$this->order->id}", ['express_code' => 'SF', 'express_no' => 'SF123456'])->assertJsonPath('code', 0);
        $this->shipping = Shipping::query()->where('order_id', $this->order->id)->firstOrFail();
    }

    private function enable(): void
    {
        DriverConfig::query()->create(['type' => 'express', 'driver' => 'kuaidi100', 'config' => ['customer' => 'c', 'key' => 'k'], 'is_enabled' => 1, 'is_default' => 1, 'sort' => 0]);
    }

    private function tracks(array $headers = [])
    {
        return $this->api('GET', "/orders/{$this->order->order_no}/tracks", [], $this->token, $headers);
    }

    public function test_buyer_sees_traces_and_repeated_views_use_the_cache(): void
    {
        $this->enable();

        $r = $this->tracks();
        $r->assertJsonPath('code', 0);
        $this->assertSame(['顺丰速运', 'SF123456', 1, '快件已揽收', ''], [
            $r->json('data.0.express_name'), $r->json('data.0.express_no'), $r->json('data.0.status'), $r->json('data.0.traces.0.context'), $r->json('data.0.message'),
        ]);
        // 查询时带上收件人手机号（顺丰等要校验尾号）
        $this->assertSame(['SF', 'SF123456', '13812345678'], $this->carrier->last);

        // 30 分钟内再看不会再向服务商查询
        $this->tracks();
        $this->tracks();
        $this->assertSame(1, $this->carrier->calls);

        // 过了缓存时间才会再查
        $this->travel(31)->minutes();
        $this->carrier->result = ['status' => 1, 'traces' => [['time' => '2026-10-09 18:00:00', 'context' => '到达深圳转运中心'], ['time' => '2026-10-09 10:00:00', 'context' => '快件已揽收']]];
        $this->assertSame('到达深圳转运中心', $this->tracks()->json('data.0.traces.0.context'));
        $this->assertSame(2, $this->carrier->calls);
    }

    public function test_a_signed_parcel_is_never_queried_again(): void
    {
        $this->enable();
        $this->carrier->result = ['status' => 2, 'traces' => [['time' => '2026-10-10 09:00:00', 'context' => '已签收']]];

        $this->assertSame(2, $this->tracks()->json('data.0.status'));
        $this->travel(50)->minutes();
        $this->adminApi('GET', "/order/ship/record/{$this->shipping->id}/track?refresh=1")->assertJsonPath('data.status', 2);
        $this->travel(20)->hours();
        $this->assertSame('已签收', $this->tracks()->json('data.0.traces.0.context'));

        $this->assertSame(1, $this->carrier->calls);
    }

    public function test_admin_refresh_is_limited_to_once_every_five_minutes(): void
    {
        $this->enable();
        $url = "/order/ship/record/{$this->shipping->id}/track";

        $this->adminApi('GET', $url)->assertJsonPath('data.traces.0.context', '快件已揽收');
        $this->adminApi('GET', $url.'?refresh=1');
        $this->assertSame(1, $this->carrier->calls, '刚查过，刷新也不会再查');

        $this->travel(6)->minutes();
        $this->adminApi('GET', $url);
        $this->assertSame(1, $this->carrier->calls, '不点刷新仍然走缓存');
        $this->adminApi('GET', $url.'?refresh=1');
        $this->assertSame(2, $this->carrier->calls);

        $this->adminApi('GET', '/order/ship/record/999999/track')->assertJsonPath('code', 1);
    }

    public function test_nothing_is_queried_when_tracking_is_off_or_the_carrier_is_unsupported(): void
    {
        // 没开通物流查询
        $off = $this->tracks();
        $this->assertSame([0, []], [$off->json('data.0.status'), $off->json('data.0.traces')]);
        $this->assertStringContainsString('没有开通', $off->json('data.0.message'));

        // 开通了，但这家快递不支持
        $this->enable();
        $this->adminApi('PUT', "/order/ship/record/{$this->shipping->id}", ['express_code' => 'OTHER', 'express_name' => '同城配送', 'express_no' => 'X1'])->assertJsonPath('code', 0);
        $this->assertStringContainsString('不支持', $this->tracks()->json('data.0.message'));

        $this->assertSame(0, $this->carrier->calls);
    }

    public function test_provider_failures_keep_the_cached_traces_and_back_off(): void
    {
        $this->enable();
        $this->tracks();

        $this->travel(31)->minutes();
        $this->carrier->error = '物流查询服务暂时不可用，请稍后再试';
        $r = $this->tracks(['X-Lang' => 'en']);

        // 之前查到的轨迹还在，只是多了一句说明（按买家语言）
        $this->assertSame('快件已揽收', $r->json('data.0.traces.0.context'));
        $this->assertSame('Tracking is temporarily unavailable. Please try again later.', $r->json('data.0.message'));

        // 出故障后短时间内不再反复请求
        $this->tracks();
        $this->tracks();
        $this->assertSame(2, $this->carrier->calls);
    }

    public function test_changing_the_tracking_number_clears_the_cached_traces(): void
    {
        $this->enable();
        $this->tracks();

        $this->adminApi('PUT', "/order/ship/record/{$this->shipping->id}", ['express_code' => 'ZTO', 'express_no' => 'ZT999'])->assertJsonPath('code', 0);
        $this->carrier->result = ['status' => 0, 'traces' => []];
        $r = $this->tracks();

        $this->assertSame(['ZT999', 0, []], [$r->json('data.0.express_no'), $r->json('data.0.status'), $r->json('data.0.traces')]);
        $this->assertStringContainsString('还没有物流信息', $r->json('data.0.message'));
        $this->assertSame(['ZTO', 'ZT999', '13812345678'], $this->carrier->last);
    }

    public function test_buyers_can_only_see_their_own_orders(): void
    {
        $this->enable();
        [, $other] = $this->makeMember();

        $this->api('GET', "/orders/{$this->order->order_no}/tracks", [], $other)->assertJsonPath('code', 1);
        $this->api('GET', "/orders/{$this->order->order_no}/tracks")->assertJsonPath('code', 401);
        $this->assertSame(0, $this->carrier->calls);
    }

    public function test_admin_can_configure_and_test_the_tracking_provider(): void
    {
        $list = $this->adminApi('GET', '/driver/express');
        $list->assertJsonPath('code', 0);
        $this->assertSame(['kuaidi100', false], [$list->json('data.0.driver'), $list->json('data.0.configured')]);

        // 没填完整不能启用
        $this->adminApi('PUT', '/driver/express/kuaidi100', ['is_enabled' => 1, 'config' => ['customer' => 'CUST']])->assertJsonPath('code', 1);
        $this->adminApi('PUT', '/driver/express/kuaidi100', ['is_enabled' => 1, 'config' => ['customer' => 'CUST', 'key' => 'SECRET-KEY']])->assertJsonPath('code', 0);

        // 授权 key 加密保存，列表里不回显
        $this->assertStringNotContainsString('SECRET-KEY', (string) \Illuminate\Support\Facades\DB::table('driver_config')->where('type', 'express')->value('config'));
        $this->assertStringNotContainsString('SECRET-KEY', $this->adminApi('GET', '/driver/express')->getContent());
    }

    // ------------------------------------------------------------------ 快递100 驱动
    private function kuaidi100(): Kuaidi100Driver
    {
        return new Kuaidi100Driver(['customer' => 'CUST', 'key' => 'KEY', 'base_url' => 'https://poll.kuaidi100.com', 'timeout' => '10', 'proxy' => '']);
    }

    public function test_kuaidi100_signs_the_request_and_maps_the_result(): void
    {
        Http::fake(['poll.kuaidi100.com/*' => Http::response(['message' => 'ok', 'state' => '3', 'data' => [
            ['time' => '2026-10-10 09:00:00', 'ftime' => '2026-10-10 09:00:00', 'context' => '已签收，签收人：本人'],
            ['time' => '2026-10-09 10:00:00', 'ftime' => '2026-10-09 10:00:00', 'context' => '快件已揽收'],
        ]])]);

        $r = $this->kuaidi100()->query('SF', ' SF123 ', '13812345678');

        $this->assertSame(2, $r['status']);
        $this->assertSame(['time' => '2026-10-10 09:00:00', 'context' => '已签收，签收人：本人'], $r['traces'][0]);
        Http::assertSent(function (HttpRequest $req) {
            $param = $req['param'];

            return $req->url() === 'https://poll.kuaidi100.com/poll/query.do' && $req['customer'] === 'CUST'
                && json_decode($param, true) === ['com' => 'shunfeng', 'num' => 'SF123', 'phone' => '13812345678', 'resultv2' => '1']
                && $req['sign'] === strtoupper(md5($param.'KEY'.'CUST'));
        });
    }

    public function test_kuaidi100_states_no_result_and_errors(): void
    {
        $driver = $this->kuaidi100();

        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'ok', 'state' => '0', 'data' => [['ftime' => '2026-10-09 10:00:00', 'context' => '运输中']]])
            ->push(['message' => 'ok', 'state' => '14', 'data' => [['ftime' => '2026-10-09 10:00:00', 'context' => '拒签']]])
            ->push(['result' => false, 'returnCode' => '500', 'message' => '查询无结果，请隔段时间再查'])
            ->push(['result' => false, 'returnCode' => '503', 'message' => '验证签名失败'])
            ->push('not json', 502)]);

        $this->assertSame(1, $driver->query('DHL', '1', '')['status']);
        $this->assertSame(3, $driver->query('UPS', '1', '')['status']);
        $this->assertSame(['status' => 0, 'traces' => []], $driver->query('YTO', '1', ''));
        foreach (['签名错误', '网关错误'] as $case) {
            try {
                $driver->query('YTO', '1', '');
                $this->fail("{$case}应该抛出异常");
            } catch (BusinessException $e) {
                // 买家看到的是通用提示，不暴露服务商的原始报错
                $this->assertSame('物流查询服务暂时不可用，请稍后再试', $e->getMessage());
            }
        }

        $this->assertTrue($driver->supports('sf'));
        $this->assertFalse($driver->supports('OTHER'));
    }

    public function test_kuaidi100_connection_test_distinguishes_bad_credentials(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['result' => false, 'returnCode' => '500', 'message' => '查询无结果，请隔段时间再查'])
            ->push(['result' => false, 'returnCode' => '503', 'message' => '验证签名失败'])]);

        $this->assertSame(['ok' => true], $this->kuaidi100()->ping());
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('验证签名失败');
        $this->kuaidi100()->ping();
    }
}
