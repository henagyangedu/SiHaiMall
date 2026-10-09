<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 商品评价：订单完成后，买家对每个商品行评价一次
        Schema::create('goods_review', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('goods_id');
            $t->unsignedBigInteger('sku_id')->default(0);
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('order_item_id')->comment('每个商品行只能评价一次');
            $t->unsignedBigInteger('member_id');
            $t->tinyInteger('rating')->comment('1-5 星');
            $t->string('content', 1000)->default('');
            $t->text('images')->nullable()->comment('图片地址 JSON 数组');
            $t->string('spec_text', 255)->default('')->comment('购买的规格（快照）');
            $t->tinyInteger('is_anonymous')->default(0);
            $t->tinyInteger('status')->default(1)->comment('1 显示 0 隐藏');
            $t->string('reply', 500)->default('')->comment('商家回复');
            $t->dateTime('replied_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique('order_item_id', 'uk_order_item');
            $t->index(['goods_id', 'status', 'id'], 'idx_goods');
            $t->index('member_id', 'idx_member');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_review');
    }
};
