<?php

namespace App\Services\Api;

use App\Drivers\DriverManager;
use App\Models\DriverConfig;
use App\Services\Common\ConfigService;
use App\Services\Common\EmailCodeService;
use App\Services\Common\LocaleService;
use App\Services\Common\SmsCodeService;

/** C 端启动配置：语言、币种、站点信息，客户端（H5 / 小程序 / APP）启动时调用一次 */
class InitService
{
    public function __construct(private LocaleService $locale, private ConfigService $config, private SmsCodeService $sms, private EmailCodeService $emailCodes,
        private MemberPrivacyService $privacy, private DriverManager $drivers) {}

    public function init(string $lang): array
    {
        return [
            'lang'          => $lang,
            'default_lang'  => $this->locale->defaultLang(),
            'languages'     => array_map(fn ($l) => ['code' => $l['code'], 'name' => $l['name']], $this->locale->languages()),
            'base_currency' => $this->locale->baseCurrency(),
            'currencies'    => $this->locale->currencies(),
            'site'          => $this->config->publicBase($lang),
            // 为 true 时：手机号注册必须带短信验证码，并可使用短信登录、短信找回密码
            'sms_enabled'   => $this->sms->enabled(),
            // 为 true 时：邮箱注册必须带邮箱验证码，并可使用邮箱找回密码
            'email_verify_enabled' => $this->emailCodes->enabled(),
            // 为 true 时：注册（含微信一键登录的首次登录）必须勾选同意用户协议和隐私政策，请求里带 agree=1
            'agree_required' => $this->privacy->agreeRequired($lang),
            // 为 true 时：小程序里可以用微信一键登录
            'wechat_login_enabled' => DriverConfig::query()->where('type', 'app')->where('driver', 'wechat_mini')->where('is_enabled', 1)->exists(),
            // 不为空时：电脑端和手机浏览器的登录页显示 Google 登录按钮（这是公开的客户端 ID，不是密钥）
            'google_client_id' => (string) $this->drivers->googleLogin()?->clientId(),
        ];
    }
}
