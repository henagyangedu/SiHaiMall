<?php

use App\Models\Admin;
use App\Models\AdminToken;
use App\Models\DriverConfig;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Google 登录点击测试的后端步骤（E2E_STEP）：
 * - 默认：把 Google 登录配置成测试用的客户端 ID，公钥地址指向流程在本机起的假服务（E2E_VALUE），并启用；给一个临时管理员令牌。
 *   开发库里已经有真实的 Google 登录配置（不是这套测试留下的）时返回 skip，不去动它。
 * - restore：删掉测试建的配置，以及测试用的那个 Google 账号注册出来的会员（没有订单，直接删干净）。
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}
const E2E_GOOGLE_CLIENT = '100000000001-e2etestclient.apps.googleusercontent.com';
const E2E_GOOGLE_SUB = 'e2e-google-account';

$row = DriverConfig::query()->where('type', 'oauth')->where('driver', 'google')->first();
$isTest = $row && (string) ($row->config['client_id'] ?? '') === E2E_GOOGLE_CLIENT;
$dropMember = function (): void {
    foreach (DB::table('member_auth')->where('platform', 'google')->where('openid', E2E_GOOGLE_SUB)->pluck('member_id') as $id) {
        DB::table('member_token')->where('member_id', $id)->delete();
        Member::withTrashed()->where('id', $id)->forceDelete();
    }
    DB::table('member_auth')->where('platform', 'google')->where('openid', E2E_GOOGLE_SUB)->delete();
};

if ((string) getenv('E2E_STEP') === 'restore') {
    if ($isTest) {
        $row->delete();
    }
    $dropMember();
    $out(['ok' => true]);

    return;
}
if ($row && !$isTest) {
    $out(['skip' => true]);

    return;
}
// 上一轮中途失败留下的先清掉
$row?->delete();
$dropMember();
DriverConfig::query()->create(['type' => 'oauth', 'driver' => 'google', 'name' => 'Google 登录', 'is_enabled' => 1, 'is_default' => 0, 'sort' => 0,
    'config' => ['client_id' => E2E_GOOGLE_CLIENT, 'certs_url' => (string) getenv('E2E_VALUE'), 'timeout' => '5', 'proxy' => '']]);

$token = Str::random(64);
AdminToken::query()->create([
    'admin_id' => Admin::query()->orderBy('id')->value('id'), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
    'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'e2e',
]);

$out(['skip' => false, 'admin_token' => $token, 'client_id' => E2E_GOOGLE_CLIENT, 'sub' => E2E_GOOGLE_SUB]);
