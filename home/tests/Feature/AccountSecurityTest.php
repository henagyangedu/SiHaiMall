<?php

namespace Tests\Feature;

use App\Mail\VerifyCodeMail;
use App\Models\Member;
use App\Models\MemberToken;
use App\Services\Common\ConfigService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\FeatureTestCase;

/** 账号与安全：没有密码的会员第一次设置密码、修改密码、绑定邮箱和手机号 */
class AccountSecurityTest extends FeatureTestCase
{
    /** 一个微信一键登录注册出来的会员：账号是随机的，没有密码、邮箱和手机号 */
    private function wechatMember(): array
    {
        $member = Member::query()->create([
            'account' => 'wx_'.Str::lower(Str::random(12)), 'password' => '', 'nickname' => '微信用户', 'lang' => 'zh-CN', 'currency' => 'CNY', 'level_id' => 1, 'status' => 1,
        ]);
        $plain = Str::random(64);
        MemberToken::query()->create([
            'member_id' => $member->id, 'token_hash' => hash('sha256', $plain), 'platform' => 'mp',
            'expires_at' => now()->addDay(), 'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'test',
        ]);

        return [$member, $plain];
    }

    public function test_a_member_without_a_password_sets_one_and_can_then_sign_in_with_it(): void
    {
        [$member, $token] = $this->wechatMember();
        $this->api('GET', '/member/profile', [], $token)->assertJsonPath('data.has_password', false);

        // 第一次设置不用原密码；太短的密码照样被拦
        $this->assertNotSame(0, $this->api('PUT', '/member/password', ['password' => '123'], $token)->json('code'));
        $this->api('PUT', '/member/password', ['password' => 'First#123'], $token)->assertJsonPath('code', 0);
        $this->api('GET', '/member/profile', [], $token)->assertJsonPath('data.has_password', true);
        $this->api('POST', '/auth/login', ['account' => $member->account, 'password' => 'First#123'])->assertJsonPath('code', 0);

        // 有了密码之后，再改就必须提供原密码
        $this->api('PUT', '/member/password', ['password' => 'Second#123'], $token)->assertJsonPath('code', 1)->assertJsonPath('msg', '请输入原密码');
        $this->api('PUT', '/member/password', ['old_password' => 'guess', 'password' => 'Second#123'], $token)->assertJsonPath('msg', '原密码不正确');
        $this->api('PUT', '/member/password', ['old_password' => 'First#123', 'password' => 'First#123'], $token)->assertJsonPath('msg', '新密码不能与原密码相同');
        $this->api('PUT', '/member/password', ['old_password' => 'First#123', 'password' => 'Second#123'], $token)->assertJsonPath('code', 0);
        $this->api('POST', '/auth/login', ['account' => $member->account, 'password' => 'First#123'])->assertJsonPath('code', 1);
    }

    public function test_changing_the_password_signs_out_other_devices_only(): void
    {
        [$member, $token] = $this->makeMember();
        $other = $this->api('POST', '/auth/login', ['account' => $member->account, 'password' => 'Secret#123'])->json('data.token');

        $this->api('PUT', '/member/password', ['old_password' => 'Secret#123', 'password' => 'Changed#123'], $token)->assertJsonPath('code', 0);

        $this->api('GET', '/member/profile', [], $token)->assertJsonPath('code', 0);
        $this->api('GET', '/member/profile', [], $other)->assertJsonPath('code', 401);
    }

    public function test_binding_an_email_makes_it_a_way_to_sign_in(): void
    {
        [$member, $token] = $this->wechatMember();
        [$someone] = $this->makeMember();

        // 别人在用的邮箱、格式不对的邮箱都绑不了
        $this->api('POST', '/member/bind', ['type' => 'email', 'email' => $someone->email], $token)->assertJsonPath('msg', '该邮箱已被其他账号使用');
        $this->assertNotSame(0, $this->api('POST', '/member/bind', ['type' => 'email', 'email' => 'not-an-email'], $token)->json('code'));
        $this->assertNotSame(0, $this->api('POST', '/member/bind', ['type' => 'email'], $token)->json('code'));

        $this->api('POST', '/member/bind', ['type' => 'email', 'email' => 'mine@example.com'], $token)
            ->assertJsonPath('code', 0)->assertJsonPath('data.email', 'mine@example.com')->assertJsonPath('data.account', $member->account);

        // 只能绑一次
        $this->api('POST', '/member/bind', ['type' => 'email', 'email' => 'other@example.com'], $token)->assertJsonPath('msg', '已绑定邮箱，如需更换请联系客服');
        // 绑定后别人不能再用它注册
        $this->api('POST', '/auth/register', ['account' => 'mine@example.com', 'password' => 'Secret#123'])->assertJsonPath('code', 1);

        // 设好密码后，可以用邮箱登录（比如到 H5 或电脑端）
        $this->api('PUT', '/member/password', ['password' => 'First#123'], $token)->assertJsonPath('code', 0);
        $this->api('POST', '/auth/login', ['account' => 'mine@example.com', 'password' => 'First#123'])->assertJsonPath('data.info.id', $member->id);
    }

    public function test_binding_an_email_needs_the_emailed_code_when_verification_is_on(): void
    {
        Mail::fake();
        app(ConfigService::class)->save('base', ['email_verify_enabled' => 1]);
        [$member, $token] = $this->wechatMember();
        $email = 'verify-bind@example.com';

        $this->api('POST', '/member/bind', ['type' => 'email', 'email' => $email], $token)->assertJsonPath('msg', '请输入邮箱验证码');

        $this->api('POST', '/auth/email-code', ['purpose' => 'register', 'email' => $email])->assertJsonPath('code', 0);
        $code = '';
        Mail::assertSent(VerifyCodeMail::class, function (VerifyCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });
        $wrong = $code === '000000' ? '000001' : '000000';
        $this->api('POST', '/member/bind', ['type' => 'email', 'email' => $email, 'code' => $wrong], $token)->assertJsonPath('code', 1);
        $this->assertSame('', (string) $member->refresh()->email);

        $this->api('POST', '/member/bind', ['type' => 'email', 'email' => $email, 'code' => $code], $token)->assertJsonPath('data.email', $email);
        // 找回密码也随之可用：这个邮箱现在算已注册
        $this->travel(2)->minutes();
        $this->api('POST', '/auth/email-code', ['purpose' => 'reset', 'email' => $email])->assertJsonPath('code', 0);
    }

    public function test_binding_a_phone_number(): void
    {
        [$member, $token] = $this->wechatMember();
        [$someone] = $this->makeMember(['phone' => '13800000001']);

        $this->api('POST', '/member/bind', ['type' => 'phone', 'mobile' => '13800000001'], $token)->assertJsonPath('msg', '该手机号已被其他账号使用');
        $this->assertNotSame(0, $this->api('POST', '/member/bind', ['type' => 'phone', 'mobile' => 'abc'], $token)->json('code'));
        $this->assertNotSame(0, $this->api('POST', '/member/bind', ['type' => 'sms', 'mobile' => '13800000002'], $token)->json('code'));

        // 没有启用短信时和注册一样不校验验证码
        $r = $this->api('POST', '/member/bind', ['type' => 'phone', 'mobile' => '13800000002', 'phone_area' => '+86'], $token);
        $r->assertJsonPath('code', 0)->assertJsonPath('data.phone', '13800000002')->assertJsonPath('data.phone_area', '86');
        $this->api('POST', '/member/bind', ['type' => 'phone', 'mobile' => '13800000003'], $token)->assertJsonPath('msg', '已绑定手机号，如需更换请联系客服');

        // 邮箱和手机号互不影响，还可以再绑邮箱
        $this->api('POST', '/member/bind', ['type' => 'email', 'email' => 'both@example.com'], $token)->assertJsonPath('code', 0);
        $this->assertSame(['13800000002', 'both@example.com'], [$member->refresh()->phone, $member->email]);
    }

    public function test_hitting_the_rate_limit_returns_a_normal_error_the_app_can_show(): void
    {
        [, $token] = $this->makeMember();
        // 改密码接口每分钟最多 10 次
        for ($i = 0; $i < 10; $i++) {
            $this->api('PUT', '/member/password', ['old_password' => 'guess', 'password' => 'Another#123'], $token)->assertJsonPath('msg', '原密码不正确');
        }

        $this->api('PUT', '/member/password', ['old_password' => 'guess', 'password' => 'Another#123'], $token)
            ->assertOk()->assertJsonPath('code', 429)->assertJsonPath('msg', '操作过于频繁，请稍后再试');
        $limited = $this->api('PUT', '/member/password', ['old_password' => 'guess', 'password' => 'Another#123'], $token, ['X-Lang' => 'en']);
        $limited->assertJsonPath('code', 429)->assertJsonPath('msg', 'Too many attempts. Please try again later.');
    }

    public function test_binding_requires_login_and_messages_follow_the_buyers_language(): void
    {
        $this->api('POST', '/member/bind', ['type' => 'email', 'email' => 'x@example.com'])->assertJsonPath('code', 401);

        [, $token] = $this->makeMember();
        $msg = $this->api('POST', '/member/bind', ['type' => 'email', 'email' => 'x@example.com'], $token, ['X-Lang' => 'en'])->json('msg');
        $this->assertSame('An email is already linked. Please contact support to change it.', $msg);
    }
}
