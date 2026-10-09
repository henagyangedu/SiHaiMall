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
 * 浏览器点击测试的准备步骤（由 e2e/lib/backend.js 通过 php artisan tinker 执行）。
 * 找到测试会员和可用的商品，造一张已支付订单（售后用）和一张已发货订单（看物流用），发一个临时管理员令牌。
 * 会往库里写测试数据，所以生产环境直接拒绝。结果用一行 "RESULT {json}" 输出。
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
    $out(['error' => '找不到测试会员 ' . getenv('E2E_ACCOUNT') . '，请先在开发库里注册这个账号（或用 E2E_ACCOUNT 指定别的）']);

    return;
}
if (!DB::table('member_address')->where('member_id', $member->id)->exists()) {
    $out(['error' => '测试会员还没有收货地址，请先添加一个（下单需要）']);

    return;
}

// 一个单规格、上架、库存充足的商品：加购、下单、售后都用它
$single = DB::table('goods as g')->join('goods_sku as k', 'k.goods_id', '=', 'g.id')
    ->where('g.spec_type', 1)->where('g.is_on_sale', 1)->whereNull('g.deleted_at')->where('k.status', 1)->where('k.stock', '>=', 10)
    ->orderBy('g.id')->first(['g.id as goods_id', 'k.id as sku_id']);
if (!$single) {
    $out(['error' => '找不到单规格、已上架、库存不少于 10 的商品，请先准备一个或给现有商品补库存']);

    return;
}
$title = fn (int $goodsId) => (string) DB::table('goods_lang')->where('goods_id', $goodsId)->where('lang', $member->lang ?: 'zh-CN')->value('title')
    ?: (string) DB::table('goods_lang')->where('goods_id', $goodsId)->value('title');
// 后台列表按默认语言显示商品名
$defaultLang = (string) DB::table('language')->where('is_default', 1)->value('code') ?: 'zh-CN';
$titleDefault = (string) DB::table('goods_lang')->where('goods_id', $single->goods_id)->where('lang', $defaultLang)->value('title');
// 多规格商品可以没有，对应的步骤会跳过
$multiId = (int) DB::table('goods')->where('spec_type', 2)->where('is_on_sale', 1)->whereNull('deleted_at')->orderBy('id')->value('id');

$newPaidOrder = function () use ($member, $single): Order {
    Artisan::call('mall:dev-order', ['member_id' => $member->id, 'sku_id' => $single->sku_id, 'qty' => 2, '--paid' => true]);

    return Order::query()->where('member_id', $member->id)->orderByDesc('id')->firstOrFail();
};

// 已发货订单：有现成的就用，没有就造一张并发货
$shipped = Order::query()->where('member_id', $member->id)->where('status', OrderStatus::SHIPPED)
    ->whereIn('id', DB::table('shipping')->select('order_id'))->orderByDesc('id')->first();
if (!$shipped) {
    $shipped = $newPaidOrder();
    DB::transaction(function () use ($shipped) {
        $items = DB::table('order_item')->where('order_id', $shipped->id)->pluck('id');
        DB::table('order_item')->where('order_id', $shipped->id)->update(['ship_status' => 1]);
        DB::table('shipping')->insert([
            'order_id' => $shipped->id, 'express_code' => 'SF', 'express_name' => '顺丰速运', 'express_no' => 'E2E' . time(),
            'item_ids' => $items->implode(','), 'shipped_at' => now(), 'created_at' => now(),
        ]);
        app(OrderStatusService::class)->transit($shipped, [OrderStatus::TO_SHIP], OrderStatus::SHIPPED, OrderStatus::OP_SYSTEM, 0, '点击测试：发货', ['shipped_at' => now()]);
    });
}

$paid = $newPaidOrder();

// 临时管理员令牌（30 分钟），cleanup.php 会删掉
$admin = Admin::query()->orderBy('id')->first();
$token = Str::random(64);
AdminToken::query()->create([
    'admin_id' => $admin->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
    'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'e2e',
]);

$out([
    'member_id' => (int) $member->id, 'nickname' => (string) $member->nickname,
    'addresses' => DB::table('member_address')->where('member_id', $member->id)->count(),
    'single' => ['goods_id' => (int) $single->goods_id, 'sku_id' => (int) $single->sku_id, 'title' => $title((int) $single->goods_id), 'title_default' => $titleDefault ?: $title((int) $single->goods_id)],
    'multi_goods_id' => $multiId,
    'paid_order_no' => $paid->order_no, 'shipped_order_no' => $shipped->order_no,
    'admin_token' => $token,
]);
