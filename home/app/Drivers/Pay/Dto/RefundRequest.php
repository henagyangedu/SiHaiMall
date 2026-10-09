<?php

namespace App\Drivers\Pay\Dto;

final class RefundRequest
{
    public function __construct(
        public readonly string $refundNo,
        public readonly string $tradeNo,
        public readonly string $channelTradeNo,
        public readonly string $currency,
        public readonly string $amount,
        public readonly string $totalAmount,
        public readonly string $reason = '',
    ) {}
}
