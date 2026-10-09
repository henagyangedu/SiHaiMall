<?php

namespace App\Drivers\Pay\Dto;

final class RefundResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $channelRefundNo = '',
        public readonly bool $pending = false,   // 渠道异步处理中，等回调
        public readonly array $raw = [],
    ) {}
}
