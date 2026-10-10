<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 到货提醒：一个会员对一个规格最多一条。到货通知发过之后 status 变 1，再次缺货要买家重新订阅
        Schema::create('stock_subscribe', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('member_id')->comment('会员');
            $t->unsignedBigInteger('goods_id')->comment('商品');
            $t->unsignedBigInteger('sku_id')->comment('规格');
            $t->tinyInteger('status')->default(0)->comment('0 等待到货 1 已通知');
            $t->dateTime('notified_at')->nullable()->comment('通知时间');
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['member_id', 'sku_id'], 'uk_member_sku');
            $t->index(['sku_id', 'status'], 'idx_sku_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_subscribe');
    }
};
