<?php

namespace App\Console\Commands;

use App\Services\Common\DistributionService;
use Illuminate\Console\Command;

class DistributionSettle extends Command
{
    protected $signature = 'mall:distribution-settle {--limit=500 : 单次最多结算多少条}';

    protected $description = '结算到期的分销佣金：订单已完成且过了售后申请期限的待结算佣金转为可提现';

    public function handle(DistributionService $service): int
    {
        $n = $service->settleDue((int) $this->option('limit'));
        $this->info("已结算 {$n} 条佣金");

        return self::SUCCESS;
    }
}
