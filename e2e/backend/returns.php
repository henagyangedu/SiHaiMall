<?php

use App\Models\Member;
use App\Models\Order;
use App\Services\Common\OrderStatusService;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * 退货退款点击测试的后端辅助步骤。环境变量 E2E_STEP：
 * - make：给测试会员造两张已发货订单（每张 2 件 E2E_SKU），返回订单号和该 SKU 当前库存
 * - state：返回订单 E2E_ORDER 的状态、支付状态、已退金额、各售后单状态和 SKU 库存，供页面操作后核对
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}

$step = getenv('E2E_STEP');
$skuId = (int) getenv('E2E_SKU');
$stock = fn (): int => (int) DB::table('goods_sku')->where('id', $skuId)->value('stock');

if ($step === 'make') {
    $member = Member::query()->where('account', getenv('E2E_ACCOUNT'))->firstOrFail();
    $status = app(OrderStatusService::class);
    $orders = [];
    foreach ([1, 2] as $n) {
        $before = (int) Order::query()->where('member_id', $member->id)->max('id');
        Artisan::call('mall:dev-order', ['member_id' => $member->id, 'sku_id' => $skuId, 'qty' => 2, '--paid' => true]);
        $order = Order::query()->where('member_id', $member->id)->where('id', '>', $before)->orderByDesc('id')->first();
        if (!$order) {
            $out(['error' => '造订单失败：' . trim(Artisan::output())]);

            return;
        }
        DB::transaction(function () use ($order, $status, $n) {
            $items = DB::table('order_item')->where('order_id', $order->id)->pluck('id');
            DB::table('order_item')->where('order_id', $order->id)->update(['ship_status' => 1]);
            DB::table('shipping')->insert([
                'order_id' => $order->id, 'express_code' => 'SF', 'express_name' => '顺丰速运', 'express_no' => 'E2ER' . time() . $n,
                'item_ids' => $items->implode(','), 'shipped_at' => now(), 'created_at' => now(),
            ]);
            $status->transit($order, [OrderStatus::TO_SHIP], OrderStatus::SHIPPED, OrderStatus::OP_SYSTEM, 0, '点击测试：发货', ['shipped_at' => now()]);
        });
        $orders[] = $order->order_no;
    }
    $out(['orders' => $orders, 'stock' => $stock()]);

    return;
}

if ($step === 'state') {
    $order = Order::query()->where('order_no', getenv('E2E_ORDER'))->firstOrFail();
    $out([
        'status' => (int) $order->status, 'pay_status' => (int) $order->pay_status,
        // 订单上没有“已退金额”字段，已退 = 已完成的售后单金额之和
        'refunded_amount' => (string) DB::table('order_refund')->where('order_id', $order->id)->where('status', 4)->sum('refund_amount'),
        'pay_amount' => (string) $order->pay_amount,
        'refunds' => DB::table('order_refund')->where('order_id', $order->id)->orderBy('id')->pluck('status')->map(fn ($s) => (int) $s)->all(),
        'stock' => $stock(),
    ]);

    return;
}

$out(['error' => '未知的步骤：' . $step]);
