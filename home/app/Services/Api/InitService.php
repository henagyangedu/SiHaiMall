<?php

namespace App\Services\Api;

use App\Services\Common\ConfigService;
use App\Services\Common\LocaleService;
use App\Services\Common\SmsCodeService;

/** C 端启动配置：语言、币种、站点信息，客户端（H5 / 小程序 / APP）启动时调用一次 */
class InitService
{
    public function __construct(private LocaleService $locale, private ConfigService $config, private SmsCodeService $sms) {}

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
        ];
    }
}
