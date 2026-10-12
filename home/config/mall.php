<?php

return [
    'default_locale' => env('MALL_DEFAULT_LOCALE', 'zh-CN'),
    'locales'        => ['zh-CN', 'en'],
    'base_currency'  => env('MALL_BASE_CURRENCY', 'CNY'),
    'currencies'     => ['CNY', 'USD'],
    // 会员登录后多少天内免重新登录（每次使用会顺延）
    'member_token_days' => (int) env('MEMBER_TOKEN_DAYS', 30),

    /*
     * 支付完成后允许跳回的域名（逗号分隔，只写域名或 IP，不带协议和端口）。
     * APP_URL 的域名总是允许的；前端（手机端 H5、PC 端）和后端不在同一个域名下时，把前端的域名加到这里，
     * 否则发起支付会被拒绝。本地开发用 127.0.0.1 访问前端时填 127.0.0.1。
     */
    'pay_return_hosts' => env('PAY_RETURN_HOSTS', ''),

    /*
     * 开放接口的主动推送（Webhook）能不能发到内网、本机的地址，以及能不能用 http。
     * 不设置时：生产环境不允许（防止有人借后台配置探测内网），其他环境允许（方便本地联调）。
     */
    'webhook_allow_private' => env('OPEN_WEBHOOK_ALLOW_PRIVATE'),
];
