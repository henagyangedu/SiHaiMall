<?php

return [
    'default_locale' => env('MALL_DEFAULT_LOCALE', 'zh-CN'),
    'locales'        => ['zh-CN', 'en'],
    'base_currency'  => env('MALL_BASE_CURRENCY', 'CNY'),
    'currencies'     => ['CNY', 'USD'],
    'order_expire_minutes' => 30,

    /*
     * 支付完成后允许跳回的域名（逗号分隔，只写域名或 IP，不带协议和端口）。
     * APP_URL 的域名总是允许的；前端（手机端 H5、PC 端）和后端不在同一个域名下时，把前端的域名加到这里，
     * 否则发起支付会被拒绝。本地开发用 127.0.0.1 访问前端时填 127.0.0.1。
     */
    'pay_return_hosts' => env('PAY_RETURN_HOSTS', ''),
];
