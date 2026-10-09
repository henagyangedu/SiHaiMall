<?php

namespace App\Drivers\Pay\Dto;

final class QueryResult
{
    public function __construct(
        public readonly string $status,           // pending / success / failed / closed
        public readonly string $channelTradeNo = '',
        public readonly string $currency = '',
        public readonly string $amount = '0',
        public readonly array $raw = [],
    ) {}
}
