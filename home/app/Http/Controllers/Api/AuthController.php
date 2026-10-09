<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\BindContactRequest;
use App\Http\Requests\Api\EmailCodeRequest;
use App\Http\Requests\Api\EmailResetPasswordRequest;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\PasswordRequest;
use App\Http\Requests\Api\ProfileRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Http\Requests\Api\SmsCodeRequest;
use App\Http\Requests\Api\SmsLoginRequest;
use App\Http\Requests\Api\WechatMiniLoginRequest;
use App\Services\Api\MemberAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(private MemberAuthService $service) {}

    public function register(RegisterRequest $request)
    {
        return ApiResponse::ok($this->service->register(
            $request->validated() + ['lang' => $this->lang($request), 'currency' => $this->currency($request)],
            $this->source($request), (string) $request->ip(), (string) $request->userAgent()
        ));
    }

    public function login(LoginRequest $request)
    {
        return ApiResponse::ok($this->service->login(
            $request->validated('account'), $request->validated('password'),
            $this->source($request), (string) $request->ip(), (string) $request->userAgent()
        ));
    }

    /** 获取短信验证码：purpose = register 注册 / login 短信登录 / reset 找回密码 */
    public function smsCode(SmsCodeRequest $request)
    {
        $this->service->sendSmsCode(
            (string) $request->validated('purpose'), (string) ($request->validated('phone_area') ?? '86'),
            (string) $request->validated('mobile'), (string) $request->ip()
        );

        return ApiResponse::ok(null, '验证码已发送');
    }

    public function loginSms(SmsLoginRequest $request)
    {
        return ApiResponse::ok($this->service->loginBySms(
            (string) $request->validated('mobile'), (string) $request->validated('code'),
            $this->source($request), (string) $request->ip(), (string) $request->userAgent()
        ));
    }

    /** 微信小程序一键登录：code 是小程序 wx.login 拿到的登录凭证 */
    public function loginWechatMini(WechatMiniLoginRequest $request)
    {
        return ApiResponse::ok($this->service->loginByWechatMini(
            (string) $request->validated('code'),
            ['invite_code' => (string) ($request->validated('invite_code') ?? ''), 'lang' => $this->lang($request), 'currency' => $this->currency($request)],
            $this->source($request), (string) $request->ip(), (string) $request->userAgent()
        ));
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        $this->service->resetPasswordBySms(
            (string) $request->validated('mobile'), (string) $request->validated('code'), (string) $request->validated('password')
        );

        return ApiResponse::ok(null, '密码已重置，请重新登录');
    }

    /** 获取邮箱验证码：purpose = register 注册 / reset 找回密码 */
    public function emailCode(EmailCodeRequest $request)
    {
        $this->service->sendEmailCode(
            (string) $request->validated('purpose'), (string) $request->validated('email'), (string) $request->ip(), $this->lang($request)
        );

        return ApiResponse::ok(null, '验证码已发送，请查收邮件');
    }

    public function resetPasswordEmail(EmailResetPasswordRequest $request)
    {
        $this->service->resetPasswordByEmail(
            (string) $request->validated('email'), (string) $request->validated('code'), (string) $request->validated('password')
        );

        return ApiResponse::ok(null, '密码已重置，请重新登录');
    }

    public function logout(Request $request)
    {
        $this->service->logout((string) $request->bearerToken());

        return ApiResponse::ok();
    }

    public function profile(Request $request)
    {
        return ApiResponse::ok($this->service->profile($this->member($request)));
    }

    public function updateProfile(ProfileRequest $request)
    {
        return ApiResponse::ok($this->service->updateProfile($this->member($request), $request->validated()));
    }

    public function password(PasswordRequest $request)
    {
        $this->service->changePassword($this->member($request), (string) ($request->validated('old_password') ?? ''), $request->validated('password'), (string) $request->bearerToken());

        return ApiResponse::ok();
    }

    /** 绑定邮箱或手机号，返回最新的个人资料 */
    public function bind(BindContactRequest $request)
    {
        return ApiResponse::ok($this->service->bindContact($this->member($request), $request->validated()));
    }
}
