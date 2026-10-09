<?php

namespace App\Drivers\Pay\Dto;

final class PayResult
{
    public function __construct(
        public readonly string $channelTradeNo = '',
        public readonly string $redirectUrl = '',   // 需要跳转的收银台
        public readonly array $clientParams = [],   // 小程序/APP 调起支付参数
        public readonly array $raw = [],
    ) {}
}
