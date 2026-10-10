<?php

namespace App\Console\Commands;

use App\Services\Common\ExchangeRateService;
use Illuminate\Console\Command;

class RateSync extends Command
{
    protected $signature = 'mall:rate-sync';

    protected $description = '汇率自动更新：给设为“自动更新”的币种取最新行情汇率（没有这样的币种时什么都不做）';

    public function handle(ExchangeRateService $service): int
    {
        $r = $service->sync();
        if (!$r['ok']) {
            $this->error('更新失败：' . $r['error'] . '，汇率没有变动');

            return self::FAILURE;
        }
        foreach ($r['items'] as $i) {
            $this->line("{$i['code']}：" . match ($i['status']) {
                'updated' => "{$i['old']} → {$i['rate']}",
                'unchanged' => '没有变化',
                default => '跳过，' . $i['msg'],
            });
        }
        $this->info("更新了 {$r['updated']} 个币种");

        return self::SUCCESS;
    }
}
