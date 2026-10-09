<?php

use App\Models\Admin;
use App\Models\AdminToken;
use App\Models\Member;
use App\Models\Order;
use App\Services\Common\OrderStatusService;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 商品评价点击测试的准备步骤：给测试会员造两张“已完成”的订单（各 1 件同一个单规格商品），
 * 一张在 PC 端评价、一张在手机端评价；再发一个临时管理员令牌（cleanup.php 会删掉）。
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}

$member = Member::query()->where('account', getenv('E2E_ACCOUNT'))->first();
if (!$member) {
    $out(['error' => '找不到测试会员 ' . getenv('E2E_ACCOUNT')]);

    return;
}
$sku = DB::table('goods as g')->join('goods_sku as k', 'k.goods_id', '=', 'g.id')
    ->where('g.spec_type', 1)->where('g.is_on_sale', 1)->whereNull('g.deleted_at')->where('k.status', 1)->where('k.stock', '>=', 2)
    ->orderBy('g.id')->first(['g.id as goods_id', 'k.id as sku_id']);
if (!$sku) {
    $out(['error' => '找不到单规格、已上架、库存不少于 2 的商品']);

    return;
}

$status = app(OrderStatusService::class);
$orders = [];
foreach ([1, 2] as $n) {
    $before = (int) Order::query()->where('member_id', $member->id)->max('id');
    Artisan::call('mall:dev-order', ['member_id' => $member->id, 'sku_id' => $sku->sku_id, 'qty' => 1, '--paid' => true]);
    $order = Order::query()->where('member_id', $member->id)->where('id', '>', $before)->orderByDesc('id')->first();
    if (!$order) {
        $out(['error' => '造订单失败：' . trim(Artisan::output())]);

        return;
    }
    DB::transaction(function () use ($order, $status) {
        DB::table('order_item')->where('order_id', $order->id)->update(['ship_status' => 1]);
        $status->transit($order, [OrderStatus::TO_SHIP], OrderStatus::SHIPPED, OrderStatus::OP_SYSTEM, 0, '点击测试：发货', ['shipped_at' => now()]);
        $status->transit($order, [OrderStatus::SHIPPED], OrderStatus::FINISHED, OrderStatus::OP_SYSTEM, 0, '点击测试：确认收货', ['finished_at' => now()]);
    });
    $orders[] = $order->order_no;
}

$admin = Admin::query()->orderBy('id')->first();
$token = Str::random(64);
AdminToken::query()->create([
    'admin_id' => $admin->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
    'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'e2e',
]);

$out(['orders' => $orders, 'goods_id' => (int) $sku->goods_id, 'nickname' => (string) $member->nickname, 'admin_token' => $token]);
