<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 开放接口的主动推送：一个应用一个接收地址
        Schema::table('open_app', function (Blueprint $t) {
            $t->string('webhook_url', 300)->default('')->comment('接收地址，空 = 不推送')->after('ip_whitelist');
            $t->text('webhook_secret')->nullable()->comment('签名密钥（加密存储）')->after('webhook_url');
            $t->json('webhook_events')->nullable()->comment('订阅的事件')->after('webhook_secret');
            $t->tinyInteger('webhook_status')->default(0)->comment('1 开启 0 关闭')->after('webhook_events');
        });

        // 推送记录：先落库再发，失败的按间隔重试
        Schema::create('open_webhook', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('app_id');
            $t->char('event_id', 32)->comment('通知的唯一编号，对方用来去重');
            $t->string('event', 40);
            $t->string('ref_no', 40)->default('')->comment('订单号或售后单号');
            $t->text('payload')->comment('发出去的正文');
            $t->tinyInteger('status')->default(0)->comment('0 待发送 1 成功 2 失败');
            $t->integer('attempts')->default(0);
            $t->dateTime('next_at')->nullable()->comment('下次重试时间');
            $t->integer('last_code')->default(0)->comment('对方返回的 HTTP 状态码');
            $t->string('last_error', 300)->default('');
            $t->dateTime('created_at')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->unique('event_id', 'uk_event');
            $t->index(['status', 'next_at'], 'idx_pending');
            $t->index(['app_id', 'created_at'], 'idx_app_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_webhook');
        Schema::table('open_app', function (Blueprint $t) {
            $t->dropColumn(['webhook_url', 'webhook_secret', 'webhook_events', 'webhook_status']);
        });
    }
};
