<?php

namespace App\Console\Commands;

use App\Services\Common\OrderRefundService;
use Illuminate\Console\Command;

class RefundCompensate extends Command
{
    protected $signature = 'mall:refund-compensate';

    protected $description = '退款补偿：向支付渠道查询已受理但还没出结果的退款，到账的自动完成售后单';

    public function handle(OrderRefundService $refunds): int
    {
        $this->info('已完成 ' . $refunds->compensate() . ' 笔');

        return self::SUCCESS;
    }
}
