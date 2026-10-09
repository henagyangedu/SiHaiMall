<?php

namespace App\Drivers\Pay\Dto;

final class PayRequest
{
    public function __construct(
        public readonly string $tradeNo,
        public readonly string $orderNo,
        public readonly string $currency,
        public readonly string $amount,      // 字符串小数，避免浮点
        public readonly string $subject,
        public readonly string $scene,       // h5 / mp / app / pc
        public readonly string $returnUrl = '',
        public readonly string $notifyUrl = '',
        public readonly array $extra = [],   // openid 等
    ) {}
}
