<?php

use App\Models\Member;
use App\Models\Order;
use App\Services\Api\OrderService;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * “微信里打开的网页付款”点击测试的后端辅助步骤。环境变量 E2E_STEP：
 * - make：给测试会员造一张待支付订单（1 件），返回订单号
 * - drop：测试结束后取消这张订单（E2E_ORDER），把占用的库存还回去
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}

$step = getenv('E2E_STEP');
$member = Member::query()->where('account', getenv('E2E_ACCOUNT'))->first();
if (!$member) {
    $out(['error' => '找不到测试会员 ' . getenv('E2E_ACCOUNT')]);

    return;
}

if ($step === 'make') {
    $skuId = (int) DB::table('goods as g')->join('goods_sku as k', 'k.goods_id', '=', 'g.id')
        ->where('g.spec_type', 1)->where('g.is_on_sale', 1)->whereNull('g.deleted_at')->where('k.status', 1)->where('k.stock', '>=', 1)
        ->orderBy('g.id')->value('k.id');
    if (!$skuId) {
        $out(['error' => '找不到单规格、已上架、有库存的商品']);

        return;
    }
    $before = (int) Order::query()->where('member_id', $member->id)->max('id');
    Artisan::call('mall:dev-order', ['member_id' => $member->id, 'sku_id' => $skuId, 'qty' => 1]);
    $order = Order::query()->where('member_id', $member->id)->where('id', '>', $before)->orderByDesc('id')->first();
    if (!$order) {
        $out(['error' => '造订单失败：' . trim(Artisan::output())]);

        return;
    }
    $out(['order_no' => $order->order_no]);

    return;
}

if ($step === 'drop') {
    $order = Order::query()->where('member_id', $member->id)->where('order_no', getenv('E2E_ORDER'))->first();
    $cancelled = false;
    if ($order && (int) $order->status === OrderStatus::PENDING) {
        app(OrderService::class)->cancel((int) $member->id, $order->order_no, '点击测试结束');
        $cancelled = true;
    }
    $out(['cancelled' => $cancelled]);

    return;
}

$out(['error' => '未知的步骤：' . $step]);
