<?php

namespace Tests\Feature;

use App\Mail\VerifyCodeMail;
use App\Services\Common\ConfigService;
use App\Services\Common\EmailCodeService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/** 邮箱验证码：发送、校验、一次性、用途隔离、限流、防爆破（配置用假对象，邮件用 Mail::fake，缓存是测试环境的内存缓存） */
class EmailCodeServiceTest extends TestCase
{
    private function service(bool $enabled = true): EmailCodeService
    {
        $config = Mockery::mock(ConfigService::class);
        $config->shouldReceive('value')->with('base', 'email_verify_enabled')->andReturn($enabled ? 1 : 0);
        $config->shouldReceive('value')->with('base', 'site_name', Mockery::any())->andReturn('GM Mall');

        return new EmailCodeService($config);
    }

    private function sentCode(): string
    {
        return Mail::sent(VerifyCodeMail::class)->last()->code;
    }

    public function test_code_is_mailed_and_verifies_once(): void
    {
        Mail::fake();
        $svc = $this->service();

        $svc->send('register', 'User@Example.com', '1.1.1.1', 'en');
        Mail::assertSent(VerifyCodeMail::class, fn (VerifyCodeMail $m) => $m->hasTo('user@example.com') && preg_match('/^\d{6}$/', $m->code) === 1);

        // 邮箱大小写不敏感；校验通过后立即失效
        $svc->verify('register', 'user@example.com', $this->sentCode());
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('验证码错误或已过期');
        $svc->verify('register', 'user@example.com', $this->sentCode());
    }

    public function test_code_is_bound_to_purpose(): void
    {
        Mail::fake();
        $svc = $this->service();
        $svc->send('register', 'a@example.com', '1.1.1.1', 'zh-CN');

        $this->expectException(BusinessException::class);
        $svc->verify('reset', 'a@example.com', $this->sentCode());
    }

    public function test_cooldown_blocks_second_send(): void
    {
        Mail::fake();
        $svc = $this->service();
        $svc->send('register', 'b@example.com', '1.1.1.1', 'en');

        try {
            $svc->send('register', 'b@example.com', '1.1.1.1', 'en');
            $this->fail('应当抛出异常');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('过于频繁', $e->getMessage());
        }
        Mail::assertSentCount(1);
    }

    public function test_five_wrong_attempts_lock_even_the_right_code(): void
    {
        Mail::fake();
        $svc = $this->service();
        $svc->send('reset', 'c@example.com', '1.1.1.1', 'en');
        $good = $this->sentCode();

        for ($i = 0; $i < 5; $i++) {
            try {
                $svc->verify('reset', 'c@example.com', '00000' . $i === $good ? '999999' : '00000' . $i);
            } catch (BusinessException) {
                // 预期：验证码错误
            }
        }

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('错误次数过多');
        $svc->verify('reset', 'c@example.com', $good);
    }

    public function test_disabled_switch_rejects_send(): void
    {
        Mail::fake();

        try {
            $this->service(false)->send('register', 'd@example.com', '1.1.1.1', 'en');
            $this->fail('应当抛出异常');
        } catch (BusinessException $e) {
            $this->assertSame('邮箱验证未开启', $e->getMessage());
        }
        Mail::assertNothingSent();
    }
}
