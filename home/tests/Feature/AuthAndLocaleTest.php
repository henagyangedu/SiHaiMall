<?php

namespace Tests\Feature;

use App\Mail\VerifyCodeMail;
use App\Models\Member;
use App\Services\Common\ConfigService;
use App\Services\Common\DistributionService;
use Illuminate\Support\Facades\Mail;
use Tests\FeatureTestCase;

/** 注册登录（邮箱验证码、邀请码）和 C 端提示的多语言 */
class AuthAndLocaleTest extends FeatureTestCase
{
    /** 取出刚才“发出”的验证码邮件里的验证码 */
    private function sentCode(string $email): string
    {
        $code = '';
        Mail::assertSent(VerifyCodeMail::class, function (VerifyCodeMail $mail) use ($email, &$code) {
            if ($mail->hasTo($email)) {
                $code = $mail->code;
            }

            return true;
        });
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        return $code;
    }

    private function assertNoChinese(?string $msg): void
    {
        $this->assertNotSame('', trim((string) $msg));
        $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', (string) $msg, "提示里还有中文：{$msg}");
    }

    public function test_register_and_login_with_a_plain_account(): void
    {
        $r = $this->api('POST', '/auth/register', ['account' => 'new@example.com', 'password' => 'Secret#123']);
        $r->assertJsonPath('code', 0);
        $this->assertNotEmpty($r->json('data.token'));

        // 密码不落明文；同一账号不能重复注册
        $this->assertNotSame('Secret#123', Member::query()->where('account', 'new@example.com')->value('password'));
        $this->api('POST', '/auth/register', ['account' => 'new@example.com', 'password' => 'Secret#123'])->assertJsonPath('code', 1);

        $this->api('POST', '/auth/login', ['account' => 'new@example.com', 'password' => 'wrong-password'])->assertJsonPath('code', 1);
        $login = $this->api('POST', '/auth/login', ['account' => 'new@example.com', 'password' => 'Secret#123']);
        $login->assertJsonPath('code', 0);

        // 令牌可用；退出后失效
        $token = $login->json('data.token');
        $this->api('GET', '/member/profile', [], $token)->assertJsonPath('code', 0);
        $this->api('POST', '/auth/logout', [], $token)->assertJsonPath('code', 0);
        $this->api('GET', '/member/profile', [], $token)->assertJsonPath('code', 401);
        $this->api('GET', '/member/profile')->assertJsonPath('code', 401);
    }

    public function test_login_is_locked_after_repeated_wrong_passwords(): void
    {
        $this->makeMember(['account' => 'lock@example.com', 'email' => 'lock@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->api('POST', '/auth/login', ['account' => 'lock@example.com', 'password' => 'nope-nope'])->assertJsonPath('code', 1);
        }

        // 第 6 次即使密码正确也被锁定
        $this->api('POST', '/auth/login', ['account' => 'lock@example.com', 'password' => 'Secret#123'])->assertJsonPath('code', 429);
    }

    public function test_email_registration_requires_the_emailed_code_when_verification_is_on(): void
    {
        app(ConfigService::class)->save('base', ['email_verify_enabled' => 1]);
        $email = 'verify@example.com';

        // 不带验证码、带错的验证码都注册不了
        $this->api('POST', '/auth/register', ['account' => $email, 'password' => 'Secret#123'])->assertJsonPath('code', 1);
        $this->api('POST', '/auth/email-code', ['purpose' => 'register', 'email' => $email])->assertJsonPath('code', 0);
        $code = $this->sentCode($email);
        $wrong = $code === '000000' ? '000001' : '000000';
        $this->api('POST', '/auth/register', ['account' => $email, 'password' => 'Secret#123', 'code' => $wrong])->assertJsonPath('code', 1);
        $this->assertSame(0, Member::query()->where('account', $email)->count());

        // 一分钟内不能重复获取
        $this->api('POST', '/auth/email-code', ['purpose' => 'register', 'email' => $email])->assertJsonPath('code', 1);
        Mail::assertSentCount(1);

        $this->api('POST', '/auth/register', ['account' => $email, 'password' => 'Secret#123', 'code' => $code])->assertJsonPath('code', 0);
        $this->assertSame(1, Member::query()->where('account', $email)->count());
    }

    public function test_password_can_be_reset_with_an_emailed_code_and_old_sessions_end(): void
    {
        app(ConfigService::class)->save('base', ['email_verify_enabled' => 1]);
        [$m, $token] = $this->makeMember(['account' => 'reset@example.com', 'email' => 'reset@example.com']);

        $this->api('POST', '/auth/email-code', ['purpose' => 'reset', 'email' => 'reset@example.com'])->assertJsonPath('code', 0);
        $code = $this->sentCode('reset@example.com');
        $this->api('POST', '/auth/reset-password-email', ['email' => 'reset@example.com', 'code' => $code, 'password' => 'Brand#New456'])->assertJsonPath('code', 0);

        $this->api('POST', '/auth/login', ['account' => 'reset@example.com', 'password' => 'Secret#123'])->assertJsonPath('code', 1);
        $this->api('POST', '/auth/login', ['account' => 'reset@example.com', 'password' => 'Brand#New456'])->assertJsonPath('code', 0);
        $this->api('GET', '/member/profile', [], $token)->assertJsonPath('code', 401);

        // 验证码用过即失效
        $this->api('POST', '/auth/reset-password-email', ['email' => 'reset@example.com', 'code' => $code, 'password' => 'Another#789'])->assertJsonPath('code', 1);
    }

    public function test_invite_code_binds_the_referrer_and_a_bad_code_blocks_registration(): void
    {
        [$referrer] = $this->makeMember();
        $code = DistributionService::inviteCode((int) $referrer->id);

        $this->api('POST', '/auth/register', ['account' => 'invited@example.com', 'password' => 'Secret#123', 'invite_code' => $code])->assertJsonPath('code', 0);
        $this->assertSame((int) $referrer->id, (int) Member::query()->where('account', 'invited@example.com')->value('parent_id'));

        // 邀请码填错：直接报错，不会悄悄注册成没有推荐人的账号
        $this->api('POST', '/auth/register', ['account' => 'typo@example.com', 'password' => 'Secret#123', 'invite_code' => $code.'X'])->assertJsonPath('code', 1);
        $this->assertSame(0, Member::query()->where('account', 'typo@example.com')->count());

        // 不填邀请码照常注册
        $this->api('POST', '/auth/register', ['account' => 'plain@example.com', 'password' => 'Secret#123'])->assertJsonPath('code', 0);
        $this->assertSame(0, (int) Member::query()->where('account', 'plain@example.com')->value('parent_id'));
    }

    public function test_buyer_facing_messages_follow_the_requested_language(): void
    {
        $en = ['X-Lang' => 'en'];

        // 业务报错
        $zh = $this->api('POST', '/auth/login', ['account' => 'nobody@example.com', 'password' => 'whatever1']);
        $this->assertMatchesRegularExpression('/\p{Han}/u', (string) $zh->json('msg'));
        $this->assertNoChinese($this->api('POST', '/auth/login', ['account' => 'nobody@example.com', 'password' => 'whatever2'], null, $en)->json('msg'));

        // 表单校验报错（含字段名）
        $invalid = $this->api('POST', '/auth/register', ['account' => 'a', 'password' => '1'], null, $en);
        $invalid->assertJsonPath('code', 422);
        $this->assertNoChinese($invalid->json('msg'));

        // 未登录
        $this->assertNoChinese($this->api('GET', '/member/profile', [], null, $en)->json('msg'));

        // 带变量的报错（库存不足）和商品名都跟着语言走
        [$m, $token] = $this->makeMember();
        $addr = $this->makeAddress($m->id);
        $sku = $this->makeSku('50.00', 1);
        $short = $this->api('POST', '/orders', ['items' => [['sku_id' => $sku, 'qty' => 5]], 'address_id' => $addr->id], $token, $en);
        $short->assertJsonPath('code', 1);
        $this->assertNoChinese($short->json('msg'));

        // 没带 X-Lang 时用会员自己的语言偏好
        [$enMember, $enToken] = $this->makeMember(['lang' => 'en']);
        $this->assertNoChinese($this->api('POST', '/orders', ['items' => [['sku_id' => $sku, 'qty' => 5]], 'address_id' => 999999], $enToken)->json('msg'));

        // 后台接口不翻译
        $this->assertMatchesRegularExpression('/\p{Han}/u', (string) $this->json('GET', '/admin-api/auth/info', [], $en)->json('msg'));
    }
}
