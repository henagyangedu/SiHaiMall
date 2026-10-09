<?php

use App\Models\Member;
use App\Models\MemberToken;
use App\Services\Common\EmailCodeService;
use App\Services\Common\SmsCodeService;
use Illuminate\Support\Str;

/**
 * “账号与安全”点击测试的后端辅助步骤。环境变量 E2E_STEP：
 * - make：造一个像微信一键登录注册出来的会员（随机账号，没有密码、邮箱、手机号）并发一个登录令牌
 * - drop：测试结束后注销这个会员（E2E_MEMBER 是它的 ID，只处理 wx_e2e 开头的账号）
 */
$out = function (array $data): void {
    echo 'RESULT ', json_encode($data, JSON_UNESCAPED_UNICODE), PHP_EOL;
};
if (app()->isProduction()) {
    $out(['error' => '生产环境不能运行点击测试']);

    return;
}

$step = getenv('E2E_STEP');

if ($step === 'make') {
    $member = Member::query()->create([
        'account' => 'wx_e2e' . Str::lower(Str::random(8)), 'password' => '', 'nickname' => '点击微信用户', 'lang' => 'zh-CN', 'currency' => 'CNY',
        'register_source' => 'mp', 'level_id' => 1, 'status' => 1,
    ]);
    $token = Str::random(64);
    MemberToken::query()->create([
        'member_id' => $member->id, 'token_hash' => hash('sha256', $token), 'platform' => 'pc',
        'expires_at' => now()->addMinutes(30), 'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'e2e',
    ]);
    $out([
        'member_id' => (int) $member->id, 'account' => $member->account, 'token' => $token,
        // 开了验证码时绑定要收邮件 / 短信，点击测试做不了，对应步骤会跳过
        'email_code' => app(EmailCodeService::class)->enabled(), 'sms_code' => app(SmsCodeService::class)->enabled(),
    ]);

    return;
}

if ($step === 'drop') {
    $member = Member::query()->where('id', (int) getenv('E2E_MEMBER'))->where('account', 'like', 'wx\_e2e%')->first();
    if ($member) {
        MemberToken::query()->where('member_id', $member->id)->delete();
        $member->delete();
    }
    $out(['dropped' => $member !== null]);

    return;
}

$out(['error' => '未知的步骤：' . $step]);
