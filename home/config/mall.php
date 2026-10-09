<?php

return [
    'default_locale' => env('MALL_DEFAULT_LOCALE', 'zh-CN'),
    'locales'        => ['zh-CN', 'en'],
    'base_currency'  => env('MALL_BASE_CURRENCY', 'CNY'),
    'currencies'     => ['CNY', 'USD'],
    'order_expire_minutes' => 30,
];
