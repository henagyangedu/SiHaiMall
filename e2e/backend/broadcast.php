<?php

use App\Models\Admin;
use App\Models\AdminToken;
use App\Models\Member;
use Illuminate\Support\Str;

/**
 * 群发消息点击测试的后端步骤：只给一个临时管理员令牌（30 分钟后失效）。
 * 群发消息本身是在后台页面上建的，只发给测试会员一个人；发出去的消息不清理，留在他的消息中心里当例子。
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}
if (!Member::query()->where('account', getenv('E2E_ACCOUNT'))->where('status', 1)->exists()) {
    $out(['error' => '找不到测试会员 ' . getenv('E2E_ACCOUNT')]);

    return;
}

$token = Str::random(64);
AdminToken::query()->create([
    'admin_id' => Admin::query()->orderBy('id')->value('id'), 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30),
    'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'e2e',
]);

$out(['admin_token' => $token]);
