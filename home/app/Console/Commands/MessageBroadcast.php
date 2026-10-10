<?php

namespace App\Console\Commands;

use App\Services\Common\MessageBroadcastService;
use Illuminate\Console\Command;

class MessageBroadcast extends Command
{
    protected $signature = 'mall:message-broadcast';

    protected $description = '把后台建好的群发消息分批写进会员的消息中心';

    public function handle(MessageBroadcastService $service): int
    {
        $this->info('已发出 ' . $service->run() . ' 条站内信');

        return self::SUCCESS;
    }
}
