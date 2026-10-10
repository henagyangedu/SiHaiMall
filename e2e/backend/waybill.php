<?php

use App\Models\Admin;
use App\Models\AdminToken;
use App\Models\DriverConfig;
use App\Models\Member;
use App\Models\Order;
use App\Models\WaybillAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 电子面单点击测试的后端步骤，用环境变量 E2E_WB_STEP 选：
 *  - prepare：给测试会员造一张已付款订单，给一个临时管理员令牌。开发库里如果已经有真实的电子面单配置或顺丰账号
 *             （不是这套测试留下的），返回 skip=true，整套流程跳过，不去动真实配置；
 *  - restore：删掉测试在页面上建的电子面单配置和顺丰账号，把那条发货记录上的面单地址清掉（它指向测试用的假服务，跑完就打不开了）。
 * 订单和发货记录（单号是假服务给的）留在库里。
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}
$mine = fn () => DriverConfig::query()->where('type', 'waybill')->where('driver', 'kuaidi100');
$isTest = fn ($row) => $row && (string) ($row->config['key'] ?? '') === 'E2E-KEY';

if (getenv('E2E_WB_STEP') === 'restore') {
    $row = $mine()->first();
    if ($isTest($row)) {
        $row->delete();
    }
    WaybillAccount::query()->where('remark', '点击测试')->delete();
    if (getenv('E2E_WB_ORDER')) {
        DB::table('shipping')->where('order_id', (int) getenv('E2E_WB_ORDER'))->update(['label_url' => '']);
    }
    $out(['ok' => true]);

    return;
}

$row = $mine()->first();
$sf = WaybillAccount::query()->where('express_code', 'SF')->first();
if (($row && !$isTest($row)) || ($sf && $sf->remark !== '点击测试')) {
    $out(['skip' => true]);

    return;
}
// 上一轮中途失败留下的：先清掉，保证从“没配置”开始
$row?->delete();
$sf?->delete();

$member = Member::query()->where('account', getenv('E2E_ACCOUNT'))->first();
if (!$member || !DB::table('member_address')->where('member_id', $member->id)->where('country_code', 'CN')->exists()) {
    $out(['error' => '找不到测试会员 ' . getenv('E2E_ACCOUNT') . '，或者他没有国内的收货地址']);

    return;
}
$sku = DB::table('goods as g')->join('goods_sku as k', 'k.goods_id', '=', 'g.id')
    ->where('g.spec_type', 1)->where('g.is_on_sale', 1)->where('g.is_virtual', 0)->whereNull('g.deleted_at')->where('k.status', 1)->where('k.stock', '>=', 5)
    ->orderBy('g.id')->value('k.id');
if (!$sku) {
    $out(['error' => '找不到单规格、已上架、库存不少于 5 的实物商品']);

    return;
}
Artisan::call('mall:dev-order', ['member_id' => $member->id, 'sku_id' => $sku, 'qty' => 1, '--paid' => true]);
$order = Order::query()->where('member_id', $member->id)->orderByDesc('id')->firstOrFail();
$addr = DB::table('order_address')->where('order_id', $order->id)->first();
if (!$addr || strtoupper((string) $addr->country_code) !== 'CN') {
    $out(['error' => '测试订单的收货地址不是国内地址（测试会员的默认地址要是国内的）']);

    return;
}

$token = Str::random(64);
AdminToken::query()->create([
    'admin_id' => Admin::query()->orderBy('id')->value('id'), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
    'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'e2e',
]);

$out(['skip' => false, 'admin_token' => $token, 'order_id' => (int) $order->id, 'order_no' => $order->order_no, 'receiver' => (string) $addr->name]);
