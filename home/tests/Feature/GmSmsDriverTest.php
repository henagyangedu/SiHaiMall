<?php

namespace Tests\Feature;

use App\Drivers\Sms\GmSmsDriver;
use App\Services\Common\SmsCodeService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** GM 短信平台驱动：签名算法、请求报文、平台各种返回的处理（平台用 Http::fake 模拟，不发真实请求） */
class GmSmsDriverTest extends TestCase
{
    private const CONFIG = [
        'base_url' => 'https://sms.example.test/api/v1', 'app_key' => 'test_app_key', 'app_secret' => 'test_app_secret',
        'scene_register' => 'reg_scene', 'scene_login' => 'login', 'scene_reset' => 'reset_password', 'timeout' => '15', 'proxy' => '',
    ];

    /** 期望值用文档里的 openssl 命令独立算出，不是用被测代码生成的 */
    public function test_sign_matches_documented_algorithm(): void
    {
        $nonce = '0123456789abcdef0123456789abcdef';

        $this->assertSame(
            '38f4cd9b4ddf058f7d83eedd923bbdaaece5166a86cccff0a3058ec17a2225ed',
            GmSmsDriver::sign('test_app_key', 'test_app_secret', '1790000000', $nonce, '{"scene":"login","mobile":"13800000000"}')
        );
        // GET 请求的报文为空字符串
        $this->assertSame(
            '1c3b93d1b7ccdf5662089d79a6edd59cb2ebcc7069915b0b0d3b983ac69c5902',
            GmSmsDriver::sign('test_app_key', 'test_app_secret', '1790000000', $nonce, '')
        );
    }

    public function test_send_code_posts_signed_raw_body(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'msg' => 'success', 'data' => []])]);

        (new GmSmsDriver(self::CONFIG))->sendCode('register', '13800000000');

        Http::assertSent(function (Request $r) {
            $body = $r->body();
            $sign = GmSmsDriver::sign('test_app_key', 'test_app_secret', $r->header('X-Gm-Timestamp')[0], $r->header('X-Gm-Nonce')[0], $body);

            return $r->method() === 'POST'
                && $r->url() === 'https://sms.example.test/api/v1/verify-code/send'
                && $body === '{"scene":"reg_scene","mobile":"13800000000"}'   // 用途映射成平台场景，报文原样发送
                && $r->header('X-Gm-AppKey')[0] === 'test_app_key'
                && strlen($r->header('X-Gm-Nonce')[0]) === 32
                && abs((int) $r->header('X-Gm-Timestamp')[0] - time()) <= 5
                && $r->header('X-Gm-Sign')[0] === $sign;
        });
    }

    public function test_send_code_maps_platform_errors_to_user_messages(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['code' => 20006, 'msg' => 'too many requests'])
            ->push(['code' => 30001, 'msg' => 'quota exhausted'])]);
        $driver = new GmSmsDriver(self::CONFIG);

        try {
            $driver->sendCode('login', '13800000000');
            $this->fail('应当抛出异常');
        } catch (BusinessException $e) {
            $this->assertSame('验证码发送过于频繁，请稍后再试', $e->getMessage());
        }
        // 额度不足等平台侧问题不把原始信息暴露给用户
        try {
            $driver->sendCode('login', '13800000000');
            $this->fail('应当抛出异常');
        } catch (BusinessException $e) {
            $this->assertSame('验证码发送失败，请稍后再试', $e->getMessage());
        }
    }

    public function test_check_code_distinguishes_wrong_code_from_platform_fault(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['code' => 0, 'msg' => 'success'])
            ->push(['code' => 60001, 'msg' => 'code mismatch'])
            ->push(['code' => 20001, 'msg' => 'sign error'])]);
        $driver = new GmSmsDriver(self::CONFIG);

        $this->assertTrue($driver->checkCode('login', '13800000000', '884218'));
        $this->assertFalse($driver->checkCode('login', '13800000000', '000000'));

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('验证码校验服务暂时不可用');
        $driver->checkCode('login', '13800000000', '884218');
    }

    public function test_ping_queries_quota_with_empty_body_signature(): void
    {
        Http::fake(['*' => Http::response(['code' => 0, 'msg' => 'success', 'data' => ['user_id' => 1, 'quotas' => [['remain' => 100]]]])]);

        $this->assertSame(['quotas' => [['remain' => 100]]], (new GmSmsDriver(self::CONFIG))->ping());

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === 'https://sms.example.test/api/v1/quota'
            && $r->header('X-Gm-Sign')[0] === GmSmsDriver::sign('test_app_key', 'test_app_secret', $r->header('X-Gm-Timestamp')[0], $r->header('X-Gm-Nonce')[0], ''));
    }

    public function test_incomplete_config_and_unknown_scene_are_rejected_before_any_request(): void
    {
        Http::fake();

        try {
            (new GmSmsDriver(['app_secret' => ''] + self::CONFIG))->ping();
            $this->fail('应当抛出异常');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('配置不完整', $e->getMessage());
        }
        try {
            (new GmSmsDriver(['scene_login' => ''] + self::CONFIG))->sendCode('login', '13800000000');
            $this->fail('应当抛出异常');
        } catch (BusinessException $e) {
            // 买家触发的请求不暴露配置细节，只给通用提示（详情写日志）
            $this->assertSame('验证码发送失败，请稍后再试', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_cn_mobile_rule(): void
    {
        $this->assertTrue(SmsCodeService::isCnMobile('86', '13800000000'));
        $this->assertTrue(SmsCodeService::isCnMobile('+86', '19912345678'));
        $this->assertTrue(SmsCodeService::isCnMobile('', '13800000000'));
        $this->assertFalse(SmsCodeService::isCnMobile('1', '13800000000'));   // 其他区号
        $this->assertFalse(SmsCodeService::isCnMobile('86', '12800000000'));  // 号段不对
        $this->assertFalse(SmsCodeService::isCnMobile('86', '1380000000'));   // 位数不对
    }
}
