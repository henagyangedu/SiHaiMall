<?php

namespace App\Console\Commands;

use App\Services\Common\MemberMessageService;
use Illuminate\Console\Command;

class MessageCleanup extends Command
{
    protected $signature = 'mall:message-cleanup';

    protected $description = '删除超过保留期（180 天）的站内信';

    public function handle(MemberMessageService $service): int
    {
        $this->info('已删除 ' . $service->cleanup() . ' 条站内信');

        return self::SUCCESS;
    }
}
