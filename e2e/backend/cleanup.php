<?php

use App\Models\AdminToken;
use App\Models\DriverConfig;
use Illuminate\Support\Facades\DB;

/**
 * 浏览器点击测试的清理步骤：删掉临时管理员令牌，以及测试时保存的假物流配置、假小程序配置、测试建的优惠券。
 * 测试产生的订单、售后单、下级账号不清理（它们是正常的业务数据，留在开发库里无害）。
 */
if (app()->isProduction()) {
    echo 'RESULT {"error":"生产环境不能运行点击测试"}', PHP_EOL;

    return;
}

$tokens = AdminToken::query()->where('user_agent', 'e2e')->delete();

// 只删测试自己写进去的那份（customer 是测试用的固定值、且没有启用），不碰真实配置
$express = 0;
$row = DriverConfig::query()->where('type', 'express')->where('driver', 'kuaidi100')->first();
if ($row && !$row->is_enabled && (($row->config ?? [])['customer'] ?? '') === 'E2E-CUSTOMER') {
    $express = (int) $row->delete();
}

// 测试建的优惠券（名字以“点击测试券”开头）和它发出去的券
$couponIds = DB::table('coupon')->where('name', 'like', '点击测试券%')->pluck('id');
$coupons = 0;
if ($couponIds->isNotEmpty()) {
    DB::table('member_coupon')->whereIn('coupon_id', $couponIds)->where('status', 0)->delete();
    $coupons = DB::table('coupon')->whereIn('id', $couponIds)->delete();
}

$mini = 0;
$row = DriverConfig::query()->where('type', 'app')->where('driver', 'wechat_mini')->first();
if ($row && !$row->is_enabled && (($row->config ?? [])['app_id'] ?? '') === 'wxe2e0000000000000') {
    $mini = (int) $row->delete();
}

echo 'RESULT ', json_encode(['tokens_deleted' => $tokens, 'express_config_deleted' => $express, 'mini_config_deleted' => $mini, 'coupons_deleted' => $coupons]), PHP_EOL;
