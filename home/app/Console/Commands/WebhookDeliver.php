<?php

namespace App\Console\Commands;

use App\Services\Common\OpenWebhookService;
use Illuminate\Console\Command;

class WebhookDeliver extends Command
{
    protected $signature = 'mall:webhook-deliver';

    protected $description = '开放接口的主动推送：重试没发成功的通知，并清掉超过保留期（30 天）的推送记录';

    public function handle(OpenWebhookService $service): int
    {
        [$ok, $fail] = $service->deliverDue();
        $this->info("发送成功 {$ok} 条，失败 {$fail} 条");
        // 清理一小时做一次就够了
        if ((int) now()->format('i') === 0) {
            $this->info('已删除 ' . $service->cleanup() . ' 条过期的推送记录');
        }

        return self::SUCCESS;
    }
}
