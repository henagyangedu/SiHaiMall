<?php

namespace App\Console\Commands;

use App\Services\Common\OrderCloseService;
use Illuminate\Console\Command;

class CloseExpiredOrders extends Command
{
    protected $signature = 'mall:close-expired-orders';

    protected $description = '关闭已超时未支付的订单并回补库存';

    public function handle(OrderCloseService $closer): int
    {
        $n = $closer->closeExpired();
        $this->info("已关闭 {$n} 个超时订单");

        return self::SUCCESS;
    }
}
