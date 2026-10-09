<?php

use App\Models\Member;
use App\Models\Order;
use App\Services\Common\ConfigService;
use App\Services\Common\OrderStatusService;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * 分销流程点击测试的后端辅助步骤。环境变量 E2E_STEP：
 * - on：临时开启分销（一级、10%、最低提现 10），返回原来的配置和测试会员当前的分销员状态
 * - order：让 E2E_BUYER 这个下级账号下单并付款、发货、收货，把完成时间挪到售后期之外，再跑一次结算
 * - off：把分销配置恢复成 E2E_DIST_CONFIG 里的原值
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}

$config = app(ConfigService::class);
$step = getenv('E2E_STEP');

if ($step === 'on') {
    $original = $config->get('distribution');
    $config->save('distribution', ['enabled' => 1, 'level' => 1, 'rate_1' => '10', 'rate_2' => '5', 'min_withdraw' => '10']);
    $member = Member::query()->where('account', getenv('E2E_ACCOUNT'))->firstOrFail();
    $status = DB::table('distributor')->where('member_id', $member->id)->value('status');
    $out(['original' => $original, 'distributor_status' => $status === null ? null : (int) $status, 'member_id' => (int) $member->id]);

    return;
}

if ($step === 'off') {
    $original = json_decode((string) getenv('E2E_DIST_CONFIG'), true);
    if (!is_array($original) || !array_key_exists('enabled', $original)) {
        $out(['error' => '没有拿到原来的分销配置，未恢复；请到后台“分销设置”检查']);

        return;
    }
    $config->save('distribution', array_intersect_key($original, array_flip(['enabled', 'level', 'rate_1', 'rate_2', 'min_withdraw'])));
    $out(['restored' => $config->get('distribution')]);

    return;
}

if ($step === 'order') {
    $buyer = Member::query()->where('account', getenv('E2E_BUYER'))->firstOrFail();
    Artisan::call('mall:dev-order', ['member_id' => $buyer->id, 'sku_id' => (int) getenv('E2E_SKU'), 'qty' => 2, '--paid' => true]);
    $order = Order::query()->where('member_id', $buyer->id)->orderByDesc('id')->first();
    if (!$order) {
        // 新注册的下级没有收货地址时 dev-order 会失败
        $out(['error' => '给下级造订单失败：' . trim(Artisan::output())]);

        return;
    }
    $status = app(OrderStatusService::class);
    DB::transaction(function () use ($order, $status) {
        DB::table('order_item')->where('order_id', $order->id)->update(['ship_status' => 1]);
        $status->transit($order, [OrderStatus::TO_SHIP], OrderStatus::SHIPPED, OrderStatus::OP_SYSTEM, 0, '点击测试：发货', ['shipped_at' => now()]);
        $status->transit($order, [OrderStatus::SHIPPED], OrderStatus::FINISHED, OrderStatus::OP_SYSTEM, 0, '点击测试：确认收货', ['finished_at' => now()->subDays(120)]);
    });
    Artisan::call('mall:distribution-settle');
    $out([
        'order_no' => $order->order_no, 'parent_id' => (int) $buyer->parent_id,
        'commission' => DB::table('distribution_commission')->where('order_id', $order->id)->orderBy('level')->get(['member_id', 'amount', 'status'])->all(),
    ]);

    return;
}

$out(['error' => '未知的步骤：' . $step]);
