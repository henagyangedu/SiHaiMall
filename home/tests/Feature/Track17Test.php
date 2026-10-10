<?php

namespace Tests\Feature;

use App\Drivers\Express\Track17Driver;
use App\Models\DriverConfig;
use App\Models\Order;
use App\Models\Shipping;
use App\Support\Exceptions\BusinessException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/** 17TRACK（境外包裹）：先登记后查询、结果解析、和快递100 同时启用时按快递公司挑服务商（17TRACK 是模拟的） */
class Track17Test extends FeatureTestCase
{
    private string $token;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        [$m, $this->token] = $this->makeMember();
        $this->order = $this->pay($this->placeOrder($this->token, $this->makeSku('100.00', 10), 1, (int) $this->makeAddress($m->id)->id));
    }

    private function driver(): Track17Driver
    {
        return new Track17Driver(['api_key' => 'KEY-17', 'base_url' => 'https://api.17track.net', 'timeout' => '10']);
    }

    private function enable(string $driver, array $config, int $default = 0): void
    {
        DriverConfig::query()->create(['type' => 'express', 'driver' => $driver, 'config' => $config, 'is_enabled' => 1, 'is_default' => $default, 'sort' => 0]);
    }

    private function ship(string $code, string $no, string $name = ''): Shipping
    {
        $this->adminApi('POST', "/order/ship/{$this->order->id}", ['express_code' => $code, 'express_no' => $no, 'express_name' => $name])->assertJsonPath('code', 0);

        return Shipping::query()->where('order_id', $this->order->id)->firstOrFail();
    }

    private function info(string $status, array $events): array
    {
        return ['code' => 0, 'data' => ['accepted' => [['number' => 'X', 'carrier' => 21051, 'track_info' => [
            'latest_status' => ['status' => $status], 'tracking' => ['providers' => [['events' => $events]]],
        ]]], 'rejected' => []]];
    }

    private function rejected(int $code): array
    {
        return ['code' => 0, 'data' => ['accepted' => [], 'rejected' => [['number' => 'X', 'error' => ['code' => $code, 'message' => 'x']]]]];
    }

    public function test_result_is_parsed_into_local_time_newest_first(): void
    {
        Http::fake(['api.17track.net/*' => Http::response($this->info('Delivered', [
            ['time_utc' => '2026-10-01T02:00:00Z', 'description' => 'Accepted at USPS Origin Facility', 'location' => 'LOS ANGELES, CA'],
            ['time_utc' => '2026-10-03T15:30:00Z', 'description' => 'Delivered, In/At Mailbox', 'location' => ''],
            ['time_iso' => '2026-10-02T08:00:00-07:00', 'description' => 'Arrived at hub NEW YORK, NY', 'location' => 'NEW YORK, NY'],
            ['time_utc' => '2026-10-02T01:00:00Z', 'description' => '', 'location' => 'nowhere'],
        ]))]);

        $r = $this->driver()->query('USPS', ' 9400111899223197428490 ');

        // 时间换成应用时区（北京时间）；地点没写在描述里才补上；空描述的不要
        $this->assertSame(2, $r['status']);
        $this->assertSame([
            ['time' => '2026-10-03 23:30:00', 'context' => 'Delivered, In/At Mailbox'],
            ['time' => '2026-10-02 23:00:00', 'context' => 'Arrived at hub NEW YORK, NY'],
            ['time' => '2026-10-01 10:00:00', 'context' => 'Accepted at USPS Origin Facility（LOS ANGELES, CA）'],
        ], $r['traces']);
        Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://api.17track.net/track/v2.2/gettrackinfo'
            && $req->header('17token') === ['KEY-17'] && $req->data() === [['number' => '9400111899223197428490', 'carrier' => 21051]]);

        // 状态：异常类的算异常，有轨迹的算运输中，什么都没有是暂无
        $this->assertSame(3, Track17Driver::parse(['latest_status' => ['status' => 'DeliveryFailure']])['status']);
        $this->assertSame(1, Track17Driver::parse(['latest_status' => ['status' => 'InTransit'], 'tracking' => ['providers' => [['events' => [['description' => 'x']]]]]])['status']);
        $this->assertSame(['status' => 0, 'traces' => []], Track17Driver::parse(['latest_status' => ['status' => 'NotFound']]));
    }

    public function test_unregistered_number_is_registered_first(): void
    {
        Http::fake([
            'api.17track.net/track/v2.2/gettrackinfo' => Http::response($this->rejected(-18019902)),
            'api.17track.net/track/v2.2/register' => Http::response(['code' => 0, 'data' => ['accepted' => [['number' => 'LX123', 'carrier' => 190008]], 'rejected' => []]]),
        ]);

        // 没登记过：登记，告诉调用方“还在抓取”。不认识的快递不传承运商，让 17TRACK 自己识别
        $this->assertSame(['status' => 0, 'traces' => [], 'pending' => true], $this->driver()->query('OTHER', 'LX123'));
        Http::assertSent(fn (HttpRequest $req) => str_ends_with($req->url(), '/register') && $req->data() === [['number' => 'LX123']]);
    }

    public function test_already_registered_and_no_info_yet_are_not_errors(): void
    {
        Http::fake([
            'api.17track.net/track/v2.2/gettrackinfo' => Http::sequence()->push($this->rejected(-18019902))->push($this->rejected(-18019909)),
            'api.17track.net/track/v2.2/register' => Http::response($this->rejected(-18019901)),
        ]);
        $this->assertTrue($this->driver()->query('DHL', 'A1')['pending']);
        $this->assertTrue($this->driver()->query('DHL', 'A1')['pending']);
    }

    public function test_errors_and_ping(): void
    {
        // 密钥不对：整个请求被拒，测试连接给出服务商的原因，查询只给通用提示
        Http::fake(['api.17track.net/*' => Http::response(['code' => -1, 'data' => ['errors' => [['code' => -18010002, 'message' => 'Invalid access token.']]]], 401)]);
        try {
            $this->driver()->ping();
            $this->fail('密钥不对时测试连接应该失败');
        } catch (BusinessException $e) {
            $this->assertSame('测试失败：Invalid access token.', $e->getMessage());
        }
        try {
            $this->driver()->query('DHL', 'A1');
            $this->fail('密钥不对时查询应该失败');
        } catch (BusinessException $e) {
            $this->assertSame('物流查询服务暂时不可用，请稍后再试', $e->getMessage());
        }

        // 没填密钥不发请求
        Http::fake();
        try {
            (new Track17Driver(['api_key' => '']))->query('DHL', 'A1');
            $this->fail('没有密钥应该报错');
        } catch (BusinessException $e) {
            $this->assertSame('物流查询还没有配置完整', $e->getMessage());
        }
    }

    public function test_admin_can_configure_and_test_the_driver(): void
    {
        Http::fake(['api.17track.net/track/v2.2/getquota' => Http::response(['code' => 0, 'data' => ['quota_total' => 100, 'quota_remain' => 99]])]);
        $list = $this->adminApi('GET', '/driver/express')->json('data');
        $this->assertSame(['kuaidi100', 'track17'], array_column($list, 'driver'));

        $this->adminApi('PUT', '/driver/express/track17', ['is_enabled' => 1, 'config' => []])->assertJsonPath('code', 1);
        $this->adminApi('PUT', '/driver/express/track17', ['is_enabled' => 1, 'config' => ['api_key' => 'SECRET-17']])->assertJsonPath('code', 0);
        $this->adminApi('POST', '/driver/express/track17/test')->assertJsonPath('code', 0);
        $this->assertStringNotContainsString('SECRET-17', $this->adminApi('GET', '/driver/express')->getContent());
    }

    public function test_pending_result_is_rechecked_sooner_and_buyer_sees_a_hint(): void
    {
        $this->enable('track17', ['api_key' => 'KEY-17']);
        Http::fake([
            'api.17track.net/track/v2.2/gettrackinfo' => Http::sequence()
                ->push($this->rejected(-18019902))
                ->push($this->info('InTransit', [['time_utc' => '2026-10-01T02:00:00Z', 'description' => 'Departed', 'location' => '']])),
            'api.17track.net/track/v2.2/register' => Http::response(['code' => 0, 'data' => ['accepted' => [['number' => 'YT1']], 'rejected' => []]]),
        ]);
        $s = $this->ship('YUNEXPRESS', 'YT1');
        $this->assertSame('云途物流', $s->express_name);

        // 第一次查：登记，买家看到“正在获取”；英文买家看到英文
        $r = $this->api('GET', "/orders/{$this->order->order_no}/tracks", [], $this->token)->json('data.0');
        $this->assertSame([0, [], '正在获取物流信息，一般几分钟后可以看到'], [$r['status'], $r['traces'], $r['message']]);
        $en = $this->api('GET', "/orders/{$this->order->order_no}/tracks", [], $this->token, ['X-Lang' => 'en'])->json('data.0.message');
        $this->assertSame('Fetching tracking updates. They usually appear within a few minutes.', $en);
        Http::assertSentCount(2);

        // 5 分钟内不重复查；过了 5 分钟（不用等半小时）再查就有轨迹了
        $this->travel(6)->minutes();
        $r = $this->api('GET', "/orders/{$this->order->order_no}/tracks", [], $this->token)->json('data.0');
        $this->assertSame([1, 'Departed', ''], [$r['status'], $r['traces'][0]['context'], $r['message']]);
        Http::assertSentCount(3);

        // 有了轨迹之后回到正常的半小时缓存
        $this->travel(10)->minutes();
        $this->api('GET', "/orders/{$this->order->order_no}/tracks", [], $this->token)->assertJsonPath('code', 0);
        Http::assertSentCount(3);
    }

    public function test_kuaidi100_goes_first_and_track17_takes_what_it_cannot_query(): void
    {
        $this->enable('track17', ['api_key' => 'KEY-17']);
        $this->enable('kuaidi100', ['customer' => 'c', 'key' => 'k']);
        Http::fake([
            'poll.kuaidi100.com/*' => Http::response(['message' => 'ok', 'state' => '0', 'data' => [['ftime' => '2026-10-09 10:00:00', 'context' => '快件已揽收']]]),
            'api.17track.net/*' => Http::response($this->info('InTransit', [['time_utc' => '2026-10-01T02:00:00Z', 'description' => 'Departed', 'location' => '']])),
        ]);

        // 顺丰：快递100 能查，就用它（和先配置哪个无关）
        $s = $this->ship('SF', 'SF123');
        $this->assertSame('快件已揽收', $this->adminApi('GET', "/order/ship/record/{$s->id}/track")->json('data.traces.0.context'));
        Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), 'kuaidi100'));
        Http::assertNotSent(fn (HttpRequest $req) => str_contains($req->url(), '17track'));

        // 改成云途（快递100 查不了）：交给 17TRACK
        $this->adminApi('PUT', "/order/ship/record/{$s->id}", ['express_code' => 'YUNEXPRESS', 'express_no' => 'YT9'])->assertJsonPath('code', 0);
        $this->assertSame('Departed', $this->adminApi('GET', "/order/ship/record/{$s->id}/track", ['refresh' => 1])->json('data.traces.0.context'));
        Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '17track') && $req->data() === [['number' => 'YT9', 'carrier' => 190008]]);
    }
}
