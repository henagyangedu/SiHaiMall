<?php

namespace App\Console\Commands;

use App\Services\Common\StockSubscribeService;
use Illuminate\Console\Command;

class RestockNotify extends Command
{
    protected $signature = 'mall:restock-notify';

    protected $description = '到货提醒：给订阅了缺货规格、现在已经有货的会员发通知（每条订阅只通知一次）';

    public function handle(StockSubscribeService $service): int
    {
        [$done, $sent] = $service->notifyRestocked();
        $this->info("到货的订阅 {$done} 条，发出通知 {$sent} 条");

        return self::SUCCESS;
    }
}
