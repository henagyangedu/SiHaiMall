<?php

namespace Tests\Feature;

use App\Models\OpenApp;
use App\Models\OpenLog;
use App\Models\Order;
use App\Models\Shipping;
use App\Support\OpenScopes;
use App\Support\OrderStatus;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\FeatureTestCase;

/** 开放接口（/open-api/v1）：密钥、权限、订单、发货、库存、财务数据 */
class OpenApiTest extends FeatureTestCase
{
    /** 在后台建一个应用，返回 [应用 ID, 密钥] */
    private function makeApp(array $scopes = [], array $attrs = []): array
    {
        $r = $this->adminApi('POST', '/open-app', $attrs + ['name' => '测试 ERP', 'scopes' => $scopes ?: array_keys(OpenScopes::ALL)]);
        $this->assertSame(0, $r->json('code'), (string) $r->json('msg'));

        return [(int) $r->json('data.id'), (string) $r->json('data.key')];
    }

    private function open(string $method, string $uri, string $key, array $data = []): TestResponse
    {
        return $this->json($method, '/open-api/v1'.$uri, $data, ['Authorization' => "Bearer {$key}"]);
    }

    /** 一张已付款、待发货的订单 */
    private function paidOrder(int $qty = 1, ?int $skuId = null): Order
    {
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);

        return $this->pay($this->placeOrder($token, $skuId ?? $this->makeSku('80.00', 20), $qty, $addr->id));
    }

    public function test_key_is_shown_once_and_only_its_hash_is_stored(): void
    {
        [$id, $key] = $this->makeApp();
        $this->assertStringStartsWith('shm_', $key);
        $app = OpenApp::query()->findOrFail($id);
        $this->assertSame(hash('sha256', $key), $app->key_hash);
        $this->assertStringNotContainsString($key, json_encode($app->getAttributes()));

        // 列表里没有密钥，只有开头几位
        $list = $this->adminApi('GET', '/open-app/list');
        $this->assertStringNotContainsString($key, $list->getContent());
        $this->assertSame(substr($key, 0, 10).'…', $list->json('data.list.0.key_hint'));
        $this->assertCount(count(OpenScopes::ALL), $list->json('data.scopes'));

        $ping = $this->open('GET', '/ping', $key);
        $ping->assertJsonPath('code', 0)->assertJsonPath('data.app', '测试 ERP')->assertJsonPath('data.timezone', config('app.timezone'));
        $this->assertNotNull(OpenApp::query()->find($id)->last_used_at);
    }

    public function test_requests_without_a_valid_key_are_rejected(): void
    {
        [$id, $key] = $this->makeApp();

        $this->json('GET', '/open-api/v1/ping')->assertJsonPath('code', 401);
        $this->open('GET', '/ping', 'shm_'.str_repeat('x', 40))->assertJsonPath('code', 401);
        // 买家的令牌、后台的令牌都不能用在这里
        [, $memberToken] = $this->makeMember();
        $this->open('GET', '/orders', $memberToken)->assertJsonPath('code', 401);

        // 停用
        $this->adminApi('PUT', "/open-app/{$id}", ['name' => '测试 ERP', 'scopes' => [OpenScopes::ORDER_READ], 'status' => 0])->assertJsonPath('code', 0);
        $this->open('GET', '/ping', $key)->assertJsonPath('code', 401);
        $this->adminApi('PUT', "/open-app/{$id}", ['name' => '测试 ERP', 'scopes' => [OpenScopes::ORDER_READ], 'status' => 1]);
        $this->open('GET', '/ping', $key)->assertJsonPath('code', 0);

        // 重新生成后旧密钥失效
        $new = (string) $this->adminApi('POST', "/open-app/{$id}/reset-key")->json('data.key');
        $this->assertNotSame($key, $new);
        $this->open('GET', '/ping', $key)->assertJsonPath('code', 401);
        $this->open('GET', '/ping', $new)->assertJsonPath('code', 0);

        // 删除
        $this->adminApi('DELETE', "/open-app/{$id}")->assertJsonPath('code', 0);
        $this->open('GET', '/ping', $new)->assertJsonPath('code', 401);
    }

    public function test_scopes_limit_what_a_key_can_do(): void
    {
        [, $key] = $this->makeApp([OpenScopes::ORDER_READ]);
        $order = $this->paidOrder();

        $this->open('GET', '/orders', $key)->assertJsonPath('code', 0);
        $this->open('POST', "/orders/{$order->order_no}/ship", $key, ['express_code' => 'SF', 'express_no' => 'SF1'])->assertJsonPath('code', 403);
        $this->open('GET', '/skus', $key)->assertJsonPath('code', 403);
        $this->open('POST', '/stock', $key, ['items' => [['sku_id' => 1, 'qty' => 1]]])->assertJsonPath('code', 403);
        $this->open('GET', '/pay-trades', $key)->assertJsonPath('code', 403);
        $this->open('GET', '/refunds', $key)->assertJsonPath('code', 403);
        $this->assertSame(OrderStatus::TO_SHIP, (int) $order->fresh()->status);
    }

    public function test_ip_whitelist(): void
    {
        [$id, $key] = $this->makeApp([], ['ip_whitelist' => "10.0.0.8\n10.0.0.9"]);
        $this->assertSame('10.0.0.8,10.0.0.9', OpenApp::query()->find($id)->ip_whitelist);
        // 测试请求的 IP 是 127.0.0.1
        $this->open('GET', '/ping', $key)->assertJsonPath('code', 401);
        $this->adminApi('PUT', "/open-app/{$id}", ['name' => '测试 ERP', 'scopes' => [OpenScopes::ORDER_READ], 'ip_whitelist' => '127.0.0.1, 10.0.0.8'])->assertJsonPath('code', 0);
        $this->open('GET', '/ping', $key)->assertJsonPath('code', 0);

        $this->adminApi('PUT', "/open-app/{$id}", ['name' => '测试 ERP', 'scopes' => [OpenScopes::ORDER_READ], 'ip_whitelist' => '10.0.0.0/24'])->assertJsonPath('code', 1);
        $this->adminApi('PUT', "/open-app/{$id}", ['name' => '测试 ERP', 'scopes' => ['nothing']])->assertJsonPath('code', 1);
    }

    public function test_order_list_and_detail(): void
    {
        [, $key] = $this->makeApp();
        $sku = $this->makeSku('80.00', 20);
        $paid = $this->paidOrder(2, $sku);
        [$m, $token] = $this->makeMember();
        $unpaid = $this->placeOrder($token, $sku, 1, $this->makeAddress($m->id)->id);

        $list = $this->open('GET', '/orders', $key);
        $list->assertJsonPath('code', 0)->assertJsonPath('data.total', 2);
        $this->assertEqualsCanonicalizing([$paid->order_no, $unpaid->order_no], array_column($list->json('data.list'), 'order_no'));

        // 按状态筛选
        $toShip = $this->open('GET', '/orders', $key, ['status' => OrderStatus::TO_SHIP]);
        $toShip->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.order_no', $paid->order_no);

        // 按更新时间增量拉取
        DB::table('order')->where('id', $unpaid->id)->update(['updated_at' => now()->subDays(3)]);
        $recent = $this->open('GET', '/orders', $key, ['updated_after' => now()->subDay()->format('Y-m-d H:i:s')]);
        $recent->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.order_no', $paid->order_no);
        $old = $this->open('GET', '/orders', $key, ['updated_before' => now()->subDay()->format('Y-m-d H:i:s')]);
        $old->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.order_no', $unpaid->order_no);
        $this->open('GET', '/orders', $key, ['updated_after' => '不是时间'])->assertJsonPath('code', 422);
        $this->open('GET', '/orders', $key, ['page_size' => 101])->assertJsonPath('code', 422);

        // 详情
        $d = $this->open('GET', "/orders/{$paid->order_no}", $key);
        $d->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', OrderStatus::TO_SHIP)->assertJsonPath('data.pay_status', OrderStatus::PAY_PAID)
            ->assertJsonPath('data.goods_amount', '160.00')->assertJsonPath('data.pay_amount', (string) $paid->pay_amount)
            ->assertJsonPath('data.refunded_amount', '0.00')
            ->assertJsonPath('data.items.0.sku_id', $sku)->assertJsonPath('data.items.0.qty', 2)->assertJsonPath('data.items.0.shipped', false)
            ->assertJsonPath('data.shipments', []);
        $this->assertSame(DB::table('goods_sku')->where('id', $sku)->value('sku_sn'), $d->json('data.items.0.sku_sn'));
        $this->assertNotEmpty($d->json('data.address.name'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $d->json('data.paid_at'));

        $this->open('GET', '/orders/NOPE', $key)->assertJsonPath('code', 404);
    }

    public function test_ship_an_order_and_fix_the_tracking_number(): void
    {
        [$appId, $key] = $this->makeApp();
        $order = $this->paidOrder();

        $this->open('POST', "/orders/{$order->order_no}/ship", $key, ['express_code' => 'NOPE', 'express_no' => 'X1'])->assertJsonPath('code', 422);
        $this->open('POST', "/orders/{$order->order_no}/ship", $key, ['express_code' => 'OTHER', 'express_no' => 'X1'])->assertJsonPath('code', 422);

        $r = $this->open('POST', "/orders/{$order->order_no}/ship", $key, ['express_code' => 'SF', 'express_no' => ' SF123456 ']);
        $r->assertJsonPath('code', 0)->assertJsonPath('data.express_no', 'SF123456')->assertJsonPath('data.express_name', '顺丰速运');
        $shipmentId = (int) $r->json('data.id');
        $this->assertSame(OrderStatus::SHIPPED, (int) $order->fresh()->status);
        // 订单日志里写明是哪个应用发的货
        $this->assertStringContainsString('开放接口（测试 ERP）', (string) DB::table('order_status_log')->where('order_id', $order->id)->orderByDesc('id')->value('remark'));

        // 对方超时重试：同一个单号再提交一次，返回同一条发货记录，不会发两次
        $again = $this->open('POST', "/orders/{$order->order_no}/ship", $key, ['express_code' => 'SF', 'express_no' => 'SF123456']);
        $again->assertJsonPath('code', 0)->assertJsonPath('data.id', $shipmentId);
        $this->assertSame(1, Shipping::query()->where('order_id', $order->id)->count());
        // 已经发完的订单换个单号再发：拒绝
        $this->open('POST', "/orders/{$order->order_no}/ship", $key, ['express_code' => 'SF', 'express_no' => 'SF999'])->assertJsonPath('code', 1);

        // 改单号
        $fix = $this->open('PUT', "/shipments/{$shipmentId}", $key, ['express_code' => 'OTHER', 'express_name' => '某某物流', 'express_no' => 'MM001']);
        $fix->assertJsonPath('code', 0)->assertJsonPath('data.express_no', 'MM001')->assertJsonPath('data.express_name', '某某物流');
        $detail = $this->open('GET', "/orders/{$order->order_no}", $key);
        $detail->assertJsonPath('data.shipments.0.express_no', 'MM001')->assertJsonPath('data.items.0.shipped', true);

        // 没付款的订单不能发
        [$m, $token] = $this->makeMember();
        $unpaid = $this->placeOrder($token, $this->makeSku(), 1, $this->makeAddress($m->id)->id);
        $this->open('POST', "/orders/{$unpaid->order_no}/ship", $key, ['express_code' => 'SF', 'express_no' => 'SF777'])->assertJsonPath('code', 1);

        // 写操作都留了记录（成功的、失败的都有），读操作不记
        $logs = OpenLog::query()->where('app_id', $appId)->get();
        $this->assertSame(7, $logs->count());
        $this->assertSame(3, $logs->where('code', 0)->count());
        $page = $this->adminApi('GET', "/open-app/{$appId}/logs");
        $page->assertJsonPath('code', 0)->assertJsonPath('data.total', 7);
    }

    public function test_partial_shipment_shows_up_in_incremental_pulls(): void
    {
        [, $key] = $this->makeApp();
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $a = $this->makeSku('30.00', 10);
        $b = $this->makeSku('40.00', 10);
        $r = $this->api('POST', '/orders', ['items' => [['sku_id' => $a, 'qty' => 1], ['sku_id' => $b, 'qty' => 1]], 'address_id' => $addr->id], $token);
        $order = $this->pay(Order::query()->where('order_no', $r->json('data.order_no'))->firstOrFail());
        $itemIds = DB::table('order_item')->where('order_id', $order->id)->orderBy('id')->pluck('id')->all();
        DB::table('order')->where('id', $order->id)->update(['updated_at' => now()->subDays(2)]);

        $this->open('POST', "/orders/{$order->order_no}/ship", $key, ['express_code' => 'ZTO', 'express_no' => 'Z1', 'item_ids' => [$itemIds[0]]])->assertJsonPath('code', 0);
        // 只发了一部分：状态还是待发货，但更新时间变了，按时间拉得到
        $this->assertSame(OrderStatus::TO_SHIP, (int) $order->fresh()->status);
        $recent = $this->open('GET', '/orders', $key, ['updated_after' => now()->subHour()->format('Y-m-d H:i:s')]);
        $recent->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.items.0.shipped', true)->assertJsonPath('data.list.0.items.1.shipped', false);

        $this->open('POST', "/orders/{$order->order_no}/ship", $key, ['express_code' => 'ZTO', 'express_no' => 'Z2'])->assertJsonPath('code', 0);
        $this->assertSame(OrderStatus::SHIPPED, (int) $order->fresh()->status);
        $this->assertCount(2, $this->open('GET', "/orders/{$order->order_no}", $key)->json('data.shipments'));
    }

    public function test_sku_list_and_stock_updates(): void
    {
        [, $key] = $this->makeApp();
        $a = $this->makeSku('50.00', 10, [], ['sku_sn' => 'ERP-A']);
        $b = $this->makeSku('60.00', 5, [], ['sku_sn' => 'ERP-B']);
        // 两个规格用了同一个货号
        $dup1 = $this->makeSku('70.00', 1, [], ['sku_sn' => 'ERP-DUP']);
        $this->makeSku('70.00', 1, [], ['sku_sn' => 'ERP-DUP']);

        $list = $this->open('GET', '/skus', $key, ['sku_sn' => 'ERP-A, ERP-B']);
        $list->assertJsonPath('code', 0)->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.sku_id', $a)->assertJsonPath('data.list.0.sku_sn', 'ERP-A')->assertJsonPath('data.list.0.stock', 10)
            ->assertJsonPath('data.list.0.price', '50.00')->assertJsonPath('data.list.0.enabled', true)->assertJsonPath('data.list.0.on_sale', true);
        $this->assertNotEmpty($list->json('data.list.0.title'));
        $this->assertNotEmpty($list->json('data.currency'));

        $r = $this->open('POST', '/stock', $key, ['items' => [
            ['sku_sn' => 'ERP-A', 'qty' => 33],                         // 设成 33
            ['sku_id' => $b, 'mode' => 'add', 'qty' => -2, 'remark' => '盘点'], // 5 − 2
            ['sku_sn' => 'ERP-DUP', 'qty' => 9],                       // 货号重复：拒绝
            ['sku_sn' => 'ERP-NONE', 'qty' => 9],                      // 不存在
            ['sku_id' => $b, 'mode' => 'add', 'qty' => -100],          // 减成负数：拒绝
        ]]);
        $r->assertJsonPath('code', 0);
        $res = $r->json('data.results');
        $this->assertSame([true, true, false, false, false], array_column($res, 'ok'));
        $this->assertSame([33, 3, null, null, null], array_column($res, 'stock'));
        $this->assertStringContainsString('对应 2 个规格', $res[2]['msg']);
        $this->assertSame(33, $this->stock($a));
        $this->assertSame(3, $this->stock($b));
        $this->assertSame(1, $this->stock($dup1));
        // 商品上的总库存跟着变
        $this->assertSame(33, (int) DB::table('goods')->where('id', DB::table('goods_sku')->where('id', $a)->value('goods_id'))->value('stock'));
        // 和后台手工调整一样写库存日志，注明来源
        $log = DB::table('stock_log')->where('sku_id', $b)->orderByDesc('id')->first();
        $this->assertSame(-2, (int) $log->change_qty);
        $this->assertSame(3, (int) $log->after_qty);
        $this->assertSame('开放接口（测试 ERP）', $log->operator);
        $this->assertSame('盘点', $log->remark);

        // 数量没变时不写日志
        $before = DB::table('stock_log')->where('sku_id', $a)->count();
        $this->open('POST', '/stock', $key, ['items' => [['sku_id' => $a, 'qty' => 33]]])->assertJsonPath('data.results.0.ok', true);
        $this->assertSame($before, DB::table('stock_log')->where('sku_id', $a)->count());

        $this->open('POST', '/stock', $key, ['items' => [['qty' => 1]]])->assertJsonPath('code', 422);
        $this->open('POST', '/stock', $key, ['items' => []])->assertJsonPath('code', 422);
    }

    public function test_pay_trades_and_refunds_for_finance(): void
    {
        [, $key] = $this->makeApp([OpenScopes::FINANCE_READ]);
        [$m, $token] = $this->makeMember();
        $order = $this->pay($this->placeOrder($token, $this->makeSku('80.00', 20), 1, $this->makeAddress($m->id)->id));

        $apply = $this->api('POST', "/orders/{$order->order_no}/refunds", ['type' => 1, 'reason' => '不想要了'], $token);
        $apply->assertJsonPath('code', 0);
        $refundNo = (string) $apply->json('data.refund_no');

        $refunds = $this->open('GET', '/refunds', $key, ['order_no' => $order->order_no]);
        $refunds->assertJsonPath('code', 0)->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.refund_no', $refundNo)->assertJsonPath('data.list.0.order_no', $order->order_no)
            ->assertJsonPath('data.list.0.order_item_id', 0)->assertJsonPath('data.list.0.status', 0)
            ->assertJsonPath('data.list.0.refund_amount', (string) $order->pay_amount);
        $this->open('GET', "/refunds/{$refundNo}", $key)->assertJsonPath('code', 0)->assertJsonPath('data.reason', '不想要了');
        $this->open('GET', '/refunds/NOPE', $key)->assertJsonPath('code', 404);
        $this->open('GET', '/refunds', $key, ['order_no' => 'NOPE'])->assertJsonPath('data.total', 0);

        // 支付流水：把一条收款流水直接写进表里（测试里的“已支付”是线下收款，不产生流水）
        DB::table('pay_trade')->insert([
            'trade_no' => 'P'.$order->order_no, 'order_id' => $order->id, 'member_id' => $m->id, 'channel' => 'stripe', 'channel_trade_no' => 'pi_123',
            'trade_type' => 1, 'currency' => $order->currency, 'amount' => $order->pay_amount, 'status' => 1,
            'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $trades = $this->open('GET', '/pay-trades', $key, ['trade_type' => 1]);
        $trades->assertJsonPath('code', 0)->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.order_no', $order->order_no)->assertJsonPath('data.list.0.channel_trade_no', 'pi_123')
            ->assertJsonPath('data.list.0.amount', (string) $order->pay_amount)->assertJsonPath('data.list.0.status', 1);
        $this->open('GET', '/pay-trades', $key, ['trade_type' => 2])->assertJsonPath('data.total', 0);
        // 响应里没有渠道的原始应答这类内部字段
        $this->assertStringNotContainsString('raw_response', $trades->getContent());
    }

    /** 每个开放接口都挂了密钥校验；除了 ping，都要求一项登记过的权限 */
    public function test_every_open_route_requires_a_key_and_a_scope(): void
    {
        $seen = 0;
        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            if (! str_starts_with($route->uri(), 'open-api/')) {
                continue;
            }
            $seen++;
            $auth = array_values(array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'open.auth')));
            $this->assertCount(1, $auth, "{$route->uri()} 没有挂 open.auth");
            if ($route->uri() === 'open-api/v1/ping') {
                continue;
            }
            $scope = substr($auth[0], strlen('open.auth:'));
            $this->assertArrayHasKey($scope, OpenScopes::ALL, "{$route->uri()} 的权限 {$scope} 没有在 OpenScopes 里登记");
        }
        $this->assertGreaterThanOrEqual(11, $seen);
    }
}
