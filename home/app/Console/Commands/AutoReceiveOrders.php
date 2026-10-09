<?php

namespace App\Console\Commands;

use App\Services\Admin\OrderShipService;
use App\Services\Common\ConfigService;
use Illuminate\Console\Command;

class AutoReceiveOrders extends Command
{
    protected $signature = 'mall:auto-receive';

    protected $description = '发货超过设定天数的订单自动确认收货';

    public function handle(OrderShipService $ship, ConfigService $config): int
    {
        $days = (int) ($config->value('trade', 'auto_receive_days') ?? 7);
        $n = $ship->autoReceive(max($days, 1));
        $this->info("已自动确认收货 {$n} 个订单");

        return self::SUCCESS;
    }
}
