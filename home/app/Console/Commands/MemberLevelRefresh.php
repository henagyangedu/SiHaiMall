<?php

namespace App\Console\Commands;

use App\Services\Common\MemberGrowthService;
use Illuminate\Console\Command;

class MemberLevelRefresh extends Command
{
    protected $signature = 'mall:member-level-refresh';

    protected $description = '按成长值重新计算全部会员的等级（只升不降）。后台调低升级门槛或新增等级后，已有会员靠它升级';

    public function handle(MemberGrowthService $service): int
    {
        $n = $service->refreshAll();
        $this->info("已升级 {$n} 位会员");

        return self::SUCCESS;
    }
}
