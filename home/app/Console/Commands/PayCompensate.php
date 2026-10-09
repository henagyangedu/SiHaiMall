<?php

namespace App\Console\Commands;

use App\Services\Common\PayService;
use Illuminate\Console\Command;

class PayCompensate extends Command
{
    protected $signature = 'mall:pay-compensate';

    protected $description = '补单：向支付渠道查询待支付单，处理回调丢失、买家未返回的已付款订单';

    public function handle(PayService $pay): int
    {
        $this->info('已入账 ' . $pay->compensate() . ' 笔');

        return self::SUCCESS;
    }
}
