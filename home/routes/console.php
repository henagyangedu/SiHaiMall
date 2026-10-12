<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 定时任务。服务器上需要每分钟执行一次调度器：* * * * * php /站点路径/home/artisan schedule:run
Schedule::command('mall:close-expired-orders')->everyMinute()->withoutOverlapping();
Schedule::command('mall:message-broadcast')->everyMinute()->withoutOverlapping();
Schedule::command('mall:webhook-deliver')->everyMinute()->withoutOverlapping();
Schedule::command('mall:pay-compensate')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('mall:restock-notify')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('mall:refund-compensate')->everyTenMinutes()->withoutOverlapping();
Schedule::command('mall:auto-receive')->hourly()->withoutOverlapping();
Schedule::command('mall:distribution-settle')->hourly()->withoutOverlapping();
Schedule::command('mall:rate-sync')->everySixHours()->withoutOverlapping();
Schedule::command('mall:message-cleanup')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('mall:member-level-refresh')->dailyAt('03:30')->withoutOverlapping();
