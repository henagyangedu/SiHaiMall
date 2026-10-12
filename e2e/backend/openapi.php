<?php

use App\Models\Admin;
use App\Models\AdminToken;
use App\Models\Member;
use App\Models\OpenApp;
use App\Models\OpenLog;
use App\Models\OpenWebhook;
use App\Models\Order;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 开放接口点击测试的后端步骤，用环境变量 E2E_STEP 选：
 *  - 不带：删掉上一轮没清干净的测试应用，给测试会员造一张已付款订单，给一个临时管理员令牌；
 *  - cleanup：删掉测试在页面上建的应用（名称以“点击测试”开头）和它的调用记录、推送记录。
 * 订单和发货记录（单号是测试编的）留在库里。
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}
$clean = function (): void {
    $ids = OpenApp::query()->where('name', 'like', '点击测试%')->pluck('id');
    OpenLog::query()->whereIn('app_id', $ids)->delete();
    OpenWebhook::query()->whereIn('app_id', $ids)->delete();
    OpenApp::query()->whereIn('id', $ids)->delete();
};
$clean();
if (getenv('E2E_STEP') === 'cleanup') {
    $out(['ok' => true]);

    return;
}

$member = Member::query()->where('account', getenv('E2E_ACCOUNT'))->first();
if (!$member || !DB::table('member_address')->where('member_id', $member->id)->exists()) {
    $out(['error' => '找不到测试会员 ' . getenv('E2E_ACCOUNT') . '，或者他没有收货地址']);

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

$token = Str::random(64);
AdminToken::query()->create([
    'admin_id' => Admin::query()->orderBy('id')->value('id'), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
    'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'e2e',
]);

$out(['admin_token' => $token, 'order_no' => $order->order_no, 'sku_id' => (int) $sku]);
