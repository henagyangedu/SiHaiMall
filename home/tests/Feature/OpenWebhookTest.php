<?php

namespace Tests\Feature;

use App\Models\OpenApp;
use App\Models\OpenWebhook;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Admin\OpenAppAdminService;
use App\Services\Admin\OrderShipService;
use App\Services\Common\OpenWebhookService;
use App\Services\Common\OrderRefundService;
use App\Support\OpenEvents;
use App\Support\OpenScopes;
use App\Support\OrderStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/** 开放接口的主动推送（Webhook）：订阅、签名、重试、重发 */
class OpenWebhookTest extends FeatureTestCase
{
    private const URL = 'https://erp.example.com/hook';

    /** 在后台建一个开了推送的应用，返回 [应用 ID, 签名密钥] */
    private function makeApp(array $events = [], array $attrs = []): array
    {
        $r = $this->adminApi('POST', '/open-app', $attrs + [
            'name' => '测试 ERP', 'scopes' => array_keys(OpenScopes::ALL),
            'webhook_url' => self::URL, 'webhook_events' => $events ?: array_keys(OpenEvents::ALL), 'webhook_status' => 1,
        ]);
        $this->assertSame(0, $r->json('code'), (string) $r->json('msg'));

        return [(int) $r->json('data.id'), (string) $r->json('data.webhook_secret')];
    }

    /** 下一张单并付款，返回 [订单, 会员令牌] */
    private function paidOrder(int $qty = 1): array
    {
        [$m, $token] = $this->makeMember();
        $order = $this->pay($this->placeOrder($token, $this->makeSku('80.00', 20), $qty, $this->makeAddress($m->id)->id));

        return [$order, $token];
    }

    /** 这个应用收到过的事件，按先后 */
    private function events(int $appId): array
    {
        return OpenWebhook::query()->where('app_id', $appId)->orderBy('id')->pluck('event')->all();
    }

    public function test_paid_order_is_pushed_with_a_valid_signature(): void
    {
        Http::fake([self::URL => Http::response('ok')]);
        [$id, $secret] = $this->makeApp();
        $this->assertStringStartsWith('whsec_', $secret);

        [$order] = $this->paidOrder();

        $w = OpenWebhook::query()->where('app_id', $id)->sole();
        $this->assertSame(OpenEvents::ORDER_PAID, $w->event);
        $this->assertSame($order->order_no, $w->ref_no);
        $this->assertSame(OpenWebhook::SUCCESS, (int) $w->status);
        $this->assertSame(1, (int) $w->attempts);
        $this->assertSame(200, (int) $w->last_code);
        $this->assertNotNull($w->delivered_at);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $req) use ($w, $secret, $order) {
            $body = $req->body();
            $ts = (int) $req->header('X-Shm-Timestamp')[0];
            $data = json_decode($body, true);

            return $req->url() === self::URL
                && $req->header('X-Shm-Event')[0] === OpenEvents::ORDER_PAID
                && $req->header('X-Shm-Delivery')[0] === $w->event_id
                && abs(time() - $ts) < 60
                && hash_equals('sha256='.OpenWebhookService::sign($secret, $ts, $body), $req->header('X-Shm-Signature')[0])
                && $data['id'] === $w->event_id && $data['event'] === OpenEvents::ORDER_PAID
                && $data['data'] === [
                    'order_no' => $order->order_no, 'status' => OrderStatus::TO_SHIP, 'pay_status' => OrderStatus::PAY_PAID,
                    'pay_amount' => (string) $order->pay_amount, 'currency' => $order->currency,
                ];
        });

        // 通知是“薄”的：没有收货人这些内容
        $this->assertStringNotContainsString('phone', $w->payload);
        // 签名密钥只在创建时给过一次，列表里没有
        $this->assertStringNotContainsString($secret, $this->adminApi('GET', '/open-app/list')->getContent());
        $this->assertNotSame($secret, OpenApp::query()->find($id)->getRawOriginal('webhook_secret'));
    }

    public function test_events_through_the_life_of_an_order(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);
        [$id] = $this->makeApp();
        [$order, $token] = $this->paidOrder(2);
        $items = $order->items()->pluck('id')->all();

        // 发货：一个包裹一条通知，带快递单号
        $this->adminApi('POST', "/order/ship/{$order->id}", ['express_code' => 'SF', 'express_no' => 'SF001'])->assertJsonPath('code', 0);
        $shipped = OpenWebhook::query()->where('app_id', $id)->where('event', OpenEvents::ORDER_SHIPPED)->sole();
        $data = json_decode($shipped->payload, true)['data'];
        $this->assertSame('SF001', $data['express_no']);
        $this->assertSame('SF', $data['express_code']);
        $this->assertSame(OrderStatus::SHIPPED, $data['status']);
        $this->assertGreaterThan(0, $data['shipment_id']);
        $this->assertNotEmpty($items);

        // 买家确认收货
        $this->api('POST', "/orders/{$order->order_no}/receive", [], $token)->assertJsonPath('code', 0);
        // 完成后申请售后、商家登记线下已退款：订单回到“已完成”或关闭，不会再发一次 order.completed
        $apply = $this->api('POST', "/orders/{$order->order_no}/refunds", ['type' => 1, 'reason' => '不想要了', 'order_item_id' => $items[0], 'qty' => 1], $token);
        $apply->assertJsonPath('code', 0);
        $refundId = (int) OrderRefund::query()->where('refund_no', $apply->json('data.refund_no'))->value('id');
        app(OrderRefundService::class)->approve($refundId, (int) $this->admin()->id);
        app(OrderRefundService::class)->pay($refundId, (int) $this->admin()->id, true);

        $this->assertSame([
            OpenEvents::ORDER_PAID, OpenEvents::ORDER_SHIPPED, OpenEvents::ORDER_COMPLETED,
            OpenEvents::REFUND_CREATED, OpenEvents::REFUND_COMPLETED,
        ], $this->events($id));
        $done = json_decode(OpenWebhook::query()->where('app_id', $id)->where('event', OpenEvents::REFUND_COMPLETED)->sole()->payload, true)['data'];
        $this->assertSame($apply->json('data.refund_no'), $done['refund_no']);
        $this->assertSame($order->order_no, $done['order_no']);
        $this->assertSame(OrderRefund::DONE, $done['status']);
        $this->assertSame(0, OpenWebhook::query()->where('status', '<>', OpenWebhook::SUCCESS)->count());
    }

    public function test_cancelled_order_is_pushed_as_closed_and_unpaid_orders_are_silent(): void
    {
        Http::fake([self::URL => Http::response('ok')]);
        [$id] = $this->makeApp();
        [$m, $token] = $this->makeMember();
        $order = $this->placeOrder($token, $this->makeSku('80.00', 20), 1, $this->makeAddress($m->id)->id);
        // 下单本身不通知
        $this->assertSame([], $this->events($id));

        $this->api('POST', "/orders/{$order->order_no}/cancel", ['reason' => '不要了'], $token)->assertJsonPath('code', 0);
        $this->assertSame([OpenEvents::ORDER_CLOSED], $this->events($id));
        $this->assertSame(OrderStatus::CANCELLED, json_decode(OpenWebhook::query()->where('app_id', $id)->sole()->payload, true)['data']['status']);
    }

    public function test_only_subscribed_events_reach_apps_that_may_see_them(): void
    {
        Http::fake([self::URL => Http::response('ok')]);
        // 只订阅了发货
        [$shipOnly] = $this->makeApp([OpenEvents::ORDER_SHIPPED]);
        // 关着推送
        [$off] = $this->makeApp([], ['webhook_status' => 0]);
        // 应用停用
        [$disabled] = $this->makeApp([], ['status' => 0]);
        // 没填地址的应用没有签名密钥
        $plain = $this->adminApi('POST', '/open-app', ['name' => '只拉取', 'scopes' => [OpenScopes::ORDER_READ]]);
        $plain->assertJsonPath('code', 0)->assertJsonPath('data.webhook_secret', null);

        [$order] = $this->paidOrder();
        $this->adminApi('POST', "/order/ship/{$order->id}", ['express_code' => 'SF', 'express_no' => 'SF002'])->assertJsonPath('code', 0);

        $this->assertSame([OpenEvents::ORDER_SHIPPED], $this->events($shipOnly));
        $this->assertSame([], $this->events($off));
        $this->assertSame([], $this->events($disabled));
        Http::assertSentCount(1);
    }

    public function test_webhook_settings_are_validated(): void
    {
        $base = ['name' => '测试 ERP', 'scopes' => [OpenScopes::ORDER_READ]];
        // 开启时要有地址和事件
        $this->adminApi('POST', '/open-app', $base + ['webhook_status' => 1, 'webhook_events' => [OpenEvents::ORDER_PAID]])->assertJsonPath('code', 1);
        $this->adminApi('POST', '/open-app', $base + ['webhook_status' => 1, 'webhook_url' => self::URL])->assertJsonPath('code', 1);
        // 地址要是完整的网址
        $this->adminApi('POST', '/open-app', $base + ['webhook_url' => 'erp.example.com/hook'])->assertJsonPath('code', 1);
        $this->adminApi('POST', '/open-app', $base + ['webhook_url' => 'ftp://erp.example.com/hook'])->assertJsonPath('code', 1);
        // 售后的事件要有“读取财务”权限
        $r = $this->adminApi('POST', '/open-app', $base + ['webhook_url' => self::URL, 'webhook_events' => [OpenEvents::REFUND_CREATED]]);
        $r->assertJsonPath('code', 1);
        $this->assertStringContainsString(OpenScopes::ALL[OpenScopes::FINANCE_READ], (string) $r->json('msg'));

        // 生产环境：只能是 https，不能指向内网或本机
        config(['mall.webhook_allow_private' => false]);
        foreach (['http://erp.example.com/hook', 'https://127.0.0.1/hook', 'https://10.0.0.8/hook', 'https://192.168.1.20:8443/hook', 'https://169.254.169.254/latest'] as $url) {
            $this->adminApi('POST', '/open-app', $base + ['webhook_url' => $url])->assertJsonPath('code', 1);
        }
        $this->adminApi('POST', '/open-app', $base + ['webhook_url' => 'https://8.8.8.8/hook'])->assertJsonPath('code', 0);

        // .env 里留空（OPEN_WEBHOOK_ALLOW_PRIVATE=）读出来是空字符串，等于没设置：非生产环境允许
        config(['mall.webhook_allow_private' => '']);
        $this->adminApi('POST', '/open-app', $base + ['webhook_url' => 'http://127.0.0.1:9000/hook'])->assertJsonPath('code', 0);
    }

    public function test_secret_appears_once_and_can_be_regenerated(): void
    {
        Http::fake([self::URL => Http::response('ok')]);
        $created = $this->adminApi('POST', '/open-app', ['name' => '测试 ERP', 'scopes' => [OpenScopes::ORDER_READ]]);
        $id = (int) $created->json('data.id');
        $fields = ['name' => '测试 ERP', 'scopes' => [OpenScopes::ORDER_READ], 'webhook_url' => self::URL, 'webhook_events' => [OpenEvents::ORDER_PAID], 'webhook_status' => 1];

        // 第一次填地址时生成，之后再保存不会重新给
        $first = (string) $this->adminApi('PUT', "/open-app/{$id}", $fields)->json('data.webhook_secret');
        $this->assertStringStartsWith('whsec_', $first);
        $this->adminApi('PUT', "/open-app/{$id}", $fields)->assertJsonPath('code', 0)->assertJsonPath('data.webhook_secret', null);

        $second = (string) $this->adminApi('POST', "/open-app/{$id}/webhook-secret")->json('data.webhook_secret');
        $this->assertNotSame($first, $second);

        // 之后的通知用新密钥签名
        $this->paidOrder();
        Http::assertSent(function (Request $req) use ($second) {
            $ts = (int) $req->header('X-Shm-Timestamp')[0];

            return $req->header('X-Shm-Signature')[0] === 'sha256='.OpenWebhookService::sign($second, $ts, $req->body());
        });
    }

    public function test_failed_deliveries_are_retried_then_given_up(): void
    {
        $up = false;
        Http::fake(function () use (&$up) {
            return $up ? Http::response('ok') : Http::response('数据库挂了', 500);
        });
        [$id] = $this->makeApp([OpenEvents::ORDER_PAID]);
        $this->paidOrder();
        $service = app(OpenWebhookService::class);

        $w = OpenWebhook::query()->where('app_id', $id)->sole();
        $this->assertSame(OpenWebhook::PENDING, (int) $w->status);
        $this->assertSame(1, (int) $w->attempts);
        $this->assertSame(500, (int) $w->last_code);
        $this->assertSame('数据库挂了', $w->last_error);
        // 第一次失败后 1 分钟再试；没到时间定时任务不发
        $this->assertEqualsWithDelta(60, now()->diffInSeconds($w->next_at), 5);
        $this->assertSame([0, 0], $service->deliverDue());

        // 一次次到时间、一次次失败，间隔按表里的来
        foreach (OpenWebhookService::RETRY_MINUTES as $i => $minutes) {
            $this->travel($minutes)->minutes();
            $this->travel(1)->seconds();
            $this->assertSame([0, 1], $service->deliverDue());
            $w->refresh();
            $this->assertSame($i + 2, (int) $w->attempts);
            if ($i < count(OpenWebhookService::RETRY_MINUTES) - 1) {
                $this->assertSame(OpenWebhook::PENDING, (int) $w->status);
                $this->assertEqualsWithDelta(OpenWebhookService::RETRY_MINUTES[$i + 1] * 60, now()->diffInSeconds($w->next_at), 5);
            }
        }
        // 都试完了：标为失败，不再自动重试
        $this->assertSame(OpenWebhook::FAILED, (int) $w->status);
        $this->assertNull($w->next_at);
        $this->travel(2)->days();
        $this->assertSame([0, 0], $service->deliverDue());
        Http::assertSentCount(1 + count(OpenWebhookService::RETRY_MINUTES));

        // 对方恢复后，管理员手工重发：编号不变
        $this->travelBack();
        $up = true;
        $r = $this->adminApi('POST', "/open-app/{$id}/webhooks/{$w->id}/resend");
        $r->assertJsonPath('code', 0)->assertJsonPath('data.status', OpenWebhook::SUCCESS)->assertJsonPath('data.event_id', $w->event_id);
        // 别的应用的推送记录重发不了
        [$other] = $this->makeApp();
        $this->adminApi('POST', "/open-app/{$other}/webhooks/{$w->id}/resend")->assertJsonPath('code', 1);

        $list = $this->adminApi('GET', "/open-app/{$id}/webhooks");
        $list->assertJsonPath('code', 0)->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.status_text', '成功');
    }

    public function test_retry_succeeds_when_the_receiver_recovers(): void
    {
        Http::fake([self::URL => Http::sequence()->pushStatus(503)->push('ok')]);
        [$id] = $this->makeApp([OpenEvents::ORDER_PAID]);
        $this->paidOrder();

        $this->travel(61)->seconds();
        $this->assertSame([1, 0], app(OpenWebhookService::class)->deliverDue());
        $w = OpenWebhook::query()->where('app_id', $id)->sole();
        $this->assertSame(OpenWebhook::SUCCESS, (int) $w->status);
        $this->assertSame(2, (int) $w->attempts);
        // 发成功的不会再发
        $this->travel(1)->days();
        $this->assertSame([0, 0], app(OpenWebhookService::class)->deliverDue());
        Http::assertSentCount(2);
    }

    public function test_connection_errors_and_closed_webhooks(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        [$id] = $this->makeApp([OpenEvents::ORDER_PAID]);
        $this->paidOrder();
        $w = OpenWebhook::query()->where('app_id', $id)->sole();
        $this->assertSame(OpenWebhook::PENDING, (int) $w->status);
        $this->assertSame(0, (int) $w->last_code);
        $this->assertStringContainsString('连接失败', $w->last_error);

        // 等重试的时候应用关掉了推送：不发了，也不再重试
        OpenApp::query()->whereKey($id)->update(['webhook_status' => 0]);
        $this->travel(61)->seconds();
        app(OpenWebhookService::class)->deliverDue();
        $w->refresh();
        $this->assertSame(OpenWebhook::FAILED, (int) $w->status);
        $this->assertStringContainsString('关闭推送', $w->last_error);
    }

    public function test_a_rolled_back_order_change_leaves_no_notification(): void
    {
        Http::fake([self::URL => Http::response('ok')]);
        [$id] = $this->makeApp();
        [$order] = $this->paidOrder();
        OpenWebhook::query()->delete();

        // 发货到一半出错：发货没发成，通知也没有
        try {
            DB::transaction(function () use ($order) {
                app(OrderShipService::class)->ship((int) $order->id, ['express_code' => 'SF', 'express_no' => 'SF003'], $this->admin());
                throw new \RuntimeException('后面的步骤失败');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(OrderStatus::TO_SHIP, (int) Order::query()->find($order->id)->status);
        $this->assertSame(0, OpenWebhook::query()->where('app_id', $id)->count());
    }

    public function test_test_notification_and_cleanup(): void
    {
        Http::fake([self::URL => Http::response('pong')]);
        [$id] = $this->makeApp([OpenEvents::ORDER_PAID]);

        $r = $this->adminApi('POST', "/open-app/{$id}/webhook-test");
        $r->assertJsonPath('code', 0)->assertJsonPath('data.event', OpenEvents::PING)->assertJsonPath('data.status', OpenWebhook::SUCCESS)->assertJsonPath('data.last_code', 200);
        Http::assertSent(fn (Request $req) => $req->header('X-Shm-Event')[0] === OpenEvents::PING);

        // 还没开启推送也能先测一下通不通；真实的通知这时候不发
        $this->adminApi('PUT', "/open-app/{$id}", ['name' => '测试 ERP', 'scopes' => array_keys(OpenScopes::ALL), 'webhook_url' => self::URL, 'webhook_status' => 0])->assertJsonPath('code', 0);
        $this->adminApi('POST', "/open-app/{$id}/webhook-test")->assertJsonPath('data.status', OpenWebhook::SUCCESS);
        $this->paidOrder();
        $this->assertSame([OpenEvents::PING, OpenEvents::PING], $this->events($id));
        OpenWebhook::query()->where('app_id', $id)->orderByDesc('id')->limit(1)->delete();

        // 没填地址的不能测
        $plain = (int) $this->adminApi('POST', '/open-app', ['name' => '只拉取', 'scopes' => [OpenScopes::ORDER_READ]])->json('data.id');
        $this->adminApi('POST', "/open-app/{$plain}/webhook-test")->assertJsonPath('code', 1);

        // 超过保留期的推送记录会被清掉
        $this->travel(OpenWebhookService::KEEP_DAYS + 1)->days();
        app(OpenAppAdminService::class)->testWebhook($id);
        $this->assertSame(1, app(OpenWebhookService::class)->cleanup());
        $this->assertSame(1, OpenWebhook::query()->where('app_id', $id)->count());
        $this->travelBack();

        // 删除应用时推送记录一起删
        $this->adminApi('DELETE', "/open-app/{$id}")->assertJsonPath('code', 0);
        $this->assertSame(0, OpenWebhook::query()->where('app_id', $id)->count());
    }

    public function test_every_event_has_a_registered_scope(): void
    {
        foreach (OpenEvents::ALL as $event => [$label, $scope]) {
            $this->assertNotSame('', $label, $event);
            $this->assertArrayHasKey($scope, OpenScopes::ALL, $event);
        }
        $this->assertCount(count(OpenEvents::ALL), $this->adminApi('GET', '/open-app/list')->json('data.events'));
    }
}
