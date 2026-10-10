<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 站内信：标题和正文在发送时按会员的语言生成并存下来，之后改模板不影响已发的
        Schema::create('member_message', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('member_id')->comment('会员');
            $t->string('scene', 60)->comment('场景，如 order_shipped');
            $t->string('title', 200)->comment('标题');
            $t->text('content')->comment('正文');
            $t->string('link_type', 20)->default('')->comment('点了跳去哪：order / refund / goods / withdraw，空 = 不跳');
            $t->string('link_value', 64)->default('')->comment('订单号 / 售后单号 / 商品 ID');
            $t->tinyInteger('is_read')->default(0);
            $t->dateTime('read_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->index(['member_id', 'is_read', 'id'], 'idx_member');
            $t->index('created_at', 'idx_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_message');
    }
};
