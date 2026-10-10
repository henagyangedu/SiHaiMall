<?php

namespace Tests\Feature;

use App\Drivers\Waybill\Kuaidi100WaybillDriver;
use App\Models\Order;
use App\Models\Shipping;
use App\Models\WaybillAccount;
use App\Support\OrderStatus;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/** 电子面单：快递账号的维护、取号发货、各种取不到号的情况，以及快递100 驱动的签名和应答处理（服务商一侧全是模拟的） */
class WaybillTest extends FeatureTestCase
{
    private string $token;

    private int $memberId;

    private int $addressId;

    private int $sku;

    protected function setUp(): void
    {
        parent::setUp();
        [$m, $this->token] = $this->makeMember();
        $this->memberId = (int) $m->id;
        $this->addressId = (int) $this->makeAddress($m->id, ['name' => '王小明', 'phone' => '13800001111', 'country_code' => 'CN', 'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'address' => '科技园 1 号'])->id;
        $this->sku = $this->makeSku('100.00', 50, [], ['weight' => '1.250']);
    }

    private function configure(): void
    {
        $this->adminApi('PUT', '/driver/waybill/kuaidi100', ['is_enabled' => 1, 'config' => [
            'key' => 'KEY', 'secret' => 'SECRET', 'sender_name' => '宠物智能生活', 'sender_mobile' => '0755-12345678', 'sender_address' => '广东省深圳市宝安区仓库路 8 号',
        ]])->assertJsonPath('code', 0);
        $this->adminApi('POST', '/waybill/accounts', ['express_code' => 'SF', 'partner_id' => '7551234567', 'partner_key' => 'PWD', 'temp_id' => 'TPL1'])->assertJsonPath('code', 0);
    }

    private function fakeOk(string $no = 'SF1000000000001'): void
    {
        Http::fake(['api.kuaidi100.com/*' => Http::response(['success' => true, 'code' => 200, 'message' => 'success', 'data' => ['taskId' => 'TASK-1', 'kuaidinum' => $no, 'label' => 'https://api.kuaidi100.com/label/getImage/abc']])]);
    }

    private function paidOrder(int $qty = 2): Order
    {
        return $this->pay($this->placeOrder($this->token, $this->sku, $qty, $this->addressId));
    }

    public function test_accounts_are_managed_and_the_password_is_never_returned(): void
    {
        // 没启用时发货弹窗里没有电子面单
        $this->assertSame(['enabled' => false, 'expresses' => []], $this->adminApi('GET', '/waybill/options')->json('data'));
        // 启用前必须把授权信息和发货人填完整
        $this->adminApi('PUT', '/driver/waybill/kuaidi100', ['is_enabled' => 1, 'config' => ['key' => 'KEY', 'secret' => 'SECRET']])->assertJsonPath('code', 1);
        $this->configure();

        $data = $this->adminApi('GET', '/waybill/accounts')->json('data');
        $this->assertSame(['SF', '顺丰速运', '7551234567', true, 'TPL1', 1], array_values(array_intersect_key($data['list'][0], array_flip(['express_code', 'express_name', 'partner_id', 'has_key', 'temp_id', 'status']))));
        $this->assertStringNotContainsString('PWD', json_encode($data));
        $this->assertContains('ZTO', array_column($data['expresses'], 'code'));
        $this->assertNotContains('DHL', array_column($data['expresses'], 'code'), '境外快递不能取电子面单');
        // 密码是加密存的
        $this->assertStringNotContainsString('PWD', (string) DB::table('waybill_account')->value('partner_key'));
        $this->assertSame(['enabled' => true, 'expresses' => [['code' => 'SF', 'name' => '顺丰速运']]], $this->adminApi('GET', '/waybill/options')->json('data'));

        // 一家快递一条；不支持的快递不能配
        $this->adminApi('POST', '/waybill/accounts', ['express_code' => 'SF', 'partner_id' => 'x'])->assertJsonPath('msg', '这家快递已经配置过账号了，直接修改它即可');
        $this->adminApi('POST', '/waybill/accounts', ['express_code' => 'DHL', 'partner_id' => 'x'])->assertJsonPath('msg', '这家快递公司暂不支持电子面单');

        // 修改时密码留空 = 不改；停用后发货弹窗里不再出现
        $id = $data['list'][0]['id'];
        $this->adminApi('PUT', "/waybill/accounts/{$id}", ['express_code' => 'SF', 'partner_id' => '7559999999', 'partner_key' => '', 'status' => 0])->assertJsonPath('code', 0);
        $row = WaybillAccount::query()->find($id);
        $this->assertSame(['7559999999', 'PWD', 0], [$row->partner_id, $row->partner_key, (int) $row->status]);
        $this->assertSame([], $this->adminApi('GET', '/waybill/options')->json('data.expresses'));
        $this->adminApi('DELETE', "/waybill/accounts/{$id}")->assertJsonPath('code', 0);
        $this->assertSame([], $this->adminApi('GET', '/waybill/accounts')->json('data.list'));
    }

    public function test_shipping_by_waybill_fetches_a_number_and_ships(): void
    {
        $this->configure();
        $this->fakeOk();
        $order = $this->paidOrder(2);

        $r = $this->adminApi('POST', "/order/ship/{$order->id}", ['waybill' => 1, 'express_code' => 'SF']);
        $r->assertJsonPath('code', 0)->assertJsonPath('data.express_no', 'SF1000000000001')->assertJsonPath('data.label_url', 'https://api.kuaidi100.com/label/getImage/abc');

        $s = Shipping::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(['SF', '顺丰速运', 'SF1000000000001', 'TASK-1', 'https://api.kuaidi100.com/label/getImage/abc'], [$s->express_code, $s->express_name, $s->express_no, $s->waybill_task_id, $s->label_url]);
        $this->assertSame(OrderStatus::SHIPPED, (int) $order->refresh()->status);
        // 后台订单详情带上面单地址；买家看到的还是普通的快递单号
        $this->assertSame('https://api.kuaidi100.com/label/getImage/abc', $this->adminApi('GET', "/order/list/{$order->id}")->json('data.shippings.0.label_url'));
        $this->assertSame('SF1000000000001', $this->api('GET', "/orders/{$order->order_no}", [], $this->token)->json('data.shippings.0.express_no'));

        // 发给快递100 的内容：签名、快递编码、账号、收件人、发货人、重量（1.25 × 2）
        Http::assertSent(function (HttpRequest $req) use ($order) {
            $p = json_decode((string) $req['param'], true);

            return $req->url() === 'https://api.kuaidi100.com/label/order' && $req['method'] === 'order' && $req['key'] === 'KEY'
                && $req['sign'] === Kuaidi100WaybillDriver::sign((string) $req['param'], (string) $req['t'], 'KEY', 'SECRET')
                && $p['kuaidicom'] === 'shunfeng' && $p['partnerId'] === '7551234567' && $p['partnerKey'] === 'PWD' && $p['tempId'] === 'TPL1'
                && $p['recMan'] === ['name' => '王小明', 'mobile' => '13800001111', 'printAddr' => '广东省深圳市南山区科技园 1 号']
                && $p['sendMan']['printAddr'] === '广东省深圳市宝安区仓库路 8 号' && $p['weight'] === '2.5' && $p['orderId'] === $order->order_no;
        });

        // 手工改了单号：面单就对不上了，清掉
        $this->adminApi('PUT', "/order/ship/record/{$s->id}", ['express_code' => 'ZTO', 'express_no' => 'ZT999'])->assertJsonPath('code', 0);
        $this->assertSame(['', ''], [$s->refresh()->waybill_task_id, $s->label_url]);
    }

    public function test_nothing_is_shipped_when_the_number_cannot_be_fetched(): void
    {
        $order = $this->paidOrder(1);
        $ship = fn (array $d = []) => $this->adminApi('POST', "/order/ship/{$order->id}", $d + ['waybill' => 1, 'express_code' => 'SF']);

        // 没启用
        $ship()->assertJsonPath('code', 1);
        $this->configure();
        // 这家快递没配账号
        $ship(['express_code' => 'ZTO'])->assertJsonPath('msg', '这家快递还没有配置电子面单账号');
        // 服务商拒绝：把原因带给管理员
        Http::fake(['api.kuaidi100.com/*' => Http::response(['success' => false, 'code' => 30001, 'message' => '月结账号或密码错误'])]);
        $ship()->assertJsonPath('msg', '取号失败：月结账号或密码错误（30001）');
        // 服务商连不上
        Http::fake(['api.kuaidi100.com/*' => fn () => throw new \RuntimeException('timeout')]);
        $this->assertStringContainsString('暂时连不上', (string) $ship()->json('msg'));

        $this->assertSame(0, Shipping::query()->where('order_id', $order->id)->count());
        $this->assertSame(OrderStatus::TO_SHIP, (int) $order->refresh()->status);
        // 手工填单号的方式不受影响，单号仍然必填
        $this->adminApi('POST', "/order/ship/{$order->id}", ['express_code' => 'SF'])->assertJsonPath('code', 422);
        $this->adminApi('POST', "/order/ship/{$order->id}", ['express_code' => 'SF', 'express_no' => 'SF-MANUAL'])->assertJsonPath('code', 0);
    }

    public function test_orders_that_cannot_ship_do_not_waste_a_number(): void
    {
        $this->configure();
        $this->fakeOk();

        // 没付款的订单、境外地址：都不会去取号
        $unpaid = $this->placeOrder($this->token, $this->sku, 1, $this->addressId);
        $this->adminApi('POST', "/order/ship/{$unpaid->id}", ['waybill' => 1, 'express_code' => 'SF'])->assertJsonPath('msg', '只有已支付的待发货订单可以发货');
        $us = (int) $this->makeAddress($this->memberId, ['country_code' => 'US', 'province' => 'California', 'city' => 'Los Angeles'])->id;
        $abroad = $this->pay($this->placeOrder($this->token, $this->sku, 1, $us));
        $this->adminApi('POST', "/order/ship/{$abroad->id}", ['waybill' => 1, 'express_code' => 'SF'])->assertJsonPath('msg', '电子面单只支持国内收货地址');
        Http::assertNothingSent();

        // 指定重量时用指定的
        $order = $this->paidOrder(1);
        $this->adminApi('POST', "/order/ship/{$order->id}", ['waybill' => 1, 'express_code' => 'SF', 'weight' => '0.8'])->assertJsonPath('code', 0);
        Http::assertSent(fn (HttpRequest $req) => json_decode((string) $req['param'], true)['weight'] === '0.8');
        // 已经发过的订单再点一次：不会再取号
        Http::fake(['api.kuaidi100.com/*' => Http::response(['success' => true, 'code' => 200, 'data' => ['kuaidinum' => 'SF2']])]);
        $this->adminApi('POST', "/order/ship/{$order->id}", ['waybill' => 1, 'express_code' => 'SF'])->assertJsonPath('code', 1);
        $this->assertSame(1, Shipping::query()->where('order_id', $order->id)->count());
    }
}
