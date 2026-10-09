<?php

namespace App\Drivers\Pay\Dto;

final class NotifyResult
{
    public function __construct(
        public readonly bool $verified,
        public readonly string $eventId,          // 渠道事件ID，用于 gm_pay_notify_log 幂等
        public readonly string $tradeNo = '',     // 商城内部支付单号
        public readonly string $channelTradeNo = '',
        public readonly string $status = '',      // success / failed / refunded
        public readonly string $currency = '',
        public readonly string $amount = '0',
        public readonly string $feeAmount = '0',
        public readonly string $rawBody = '',
        public readonly string $ackBody = 'success', // 返回给渠道的应答内容
    ) {}
}
