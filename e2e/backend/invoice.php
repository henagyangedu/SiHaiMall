<?php

use App\Models\Admin;
use App\Models\AdminToken;
use App\Models\Member;
use App\Models\Order;
use App\Services\Common\ConfigService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 发票点击测试的后端步骤，用环境变量 E2E_INV_STEP 选：
 *  - prepare：打开“允许申请发票”（记下原来的值；开票说明没填过就填一段示例），给测试会员造两张已付款订单，给一个临时管理员令牌；
 *  - restore：把“允许申请发票”恢复成 prepare 时记下的值（E2E_INV_ENABLED）。
 * 订单和开出来的发票不清理，留在开发库里当例子。
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}
$config = app(ConfigService::class);

if (getenv('E2E_INV_STEP') === 'restore') {
    $config->save('trade', ['invoice_enabled' => (int) getenv('E2E_INV_ENABLED')]);
    $out(['ok' => true]);

    return;
}

$member = Member::query()->where('account', getenv('E2E_ACCOUNT'))->first();
if (!$member || !DB::table('member_address')->where('member_id', $member->id)->exists()) {
    $out(['error' => '找不到测试会员 ' . getenv('E2E_ACCOUNT') . '，或者他还没有收货地址']);

    return;
}
$sku = DB::table('goods as g')->join('goods_sku as k', 'k.goods_id', '=', 'g.id')
    ->where('g.spec_type', 1)->where('g.is_on_sale', 1)->whereNull('g.deleted_at')->where('k.status', 1)->where('k.stock', '>=', 5)
    ->orderBy('g.id')->value('k.id');
if (!$sku) {
    $out(['error' => '找不到单规格、已上架、库存不少于 5 的商品']);

    return;
}

$was = (int) $config->value('trade', 'invoice_enabled');
$notice = (array) $config->get('trade')['invoice_notice'];
$save = ['invoice_enabled' => 1];
if (trim(implode('', $notice)) === '') {
    $save['invoice_notice'] = ['zh-CN' => "开具电子普通发票，内容为商品明细，付款后 3 个工作日内开出。\n需要增值税专用发票请填写完整的企业开票资料。"];
}
$config->save('trade', $save);

$orders = [];
foreach ([1, 2] as $_) {
    Artisan::call('mall:dev-order', ['member_id' => $member->id, 'sku_id' => $sku, 'qty' => 1, '--paid' => true]);
    $orders[] = Order::query()->where('member_id', $member->id)->orderByDesc('id')->firstOrFail();
}

$token = Str::random(64);
AdminToken::query()->create([
    'admin_id' => Admin::query()->orderBy('id')->value('id'), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
    'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'e2e',
]);

$out([
    'order_no' => $orders[0]->order_no, 'pay_amount' => (string) $orders[0]->pay_amount, 'second_no' => $orders[1]->order_no,
    'admin_token' => $token, 'enabled_was' => $was,
]);
