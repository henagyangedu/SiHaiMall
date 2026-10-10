<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 后台群发的站内信：这里只存“发什么、发给谁、发到哪了”，实际的消息由定时任务分批写进 member_message
        Schema::create('message_broadcast', function (Blueprint $t) {
            $t->id();
            $t->tinyInteger('target_type')->default(1)->comment('发给谁：1 全部会员 2 指定等级 3 指定会员');
            $t->text('target_ids')->nullable()->comment('等级 ID 或会员 ID 的 JSON 数组；全部会员时为空');
            $t->string('link_type', 20)->default('')->comment('点了跳去哪：goods / article / coupon，空 = 不跳');
            $t->string('link_value', 64)->default('')->comment('商品 ID / 文章 ID');
            $t->tinyInteger('status')->default(0)->comment('0 待发送 1 发送中 2 已完成 3 已撤回');
            $t->unsignedBigInteger('last_member_id')->default(0)->comment('已发到哪个会员（续发用）');
            $t->unsignedInteger('sent_count')->default(0)->comment('实际发了多少人');
            $t->unsignedBigInteger('admin_id')->default(0)->comment('谁发的');
            $t->dateTime('finished_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'id'], 'idx_status');
        });

        Schema::create('message_broadcast_lang', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('broadcast_id');
            $t->string('lang', 10);
            $t->string('title', 200)->default('');
            $t->text('content')->nullable();
            $t->unique(['broadcast_id', 'lang'], 'uk_broadcast_lang');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_broadcast_lang');
        Schema::dropIfExists('message_broadcast');
    }
};
