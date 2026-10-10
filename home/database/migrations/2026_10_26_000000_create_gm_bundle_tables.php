<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 搭配套餐：一组具体规格的商品，订单里凑齐一套就立减一笔钱
        Schema::create('bundle', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->comment('套餐名称（只在后台显示）');
            $t->decimal('reduce_amount', 12, 2)->comment('凑齐一套立减多少（基准币种）');
            $t->dateTime('start_at')->nullable()->comment('开始时间，空 = 不限');
            $t->dateTime('end_at')->nullable()->comment('结束时间，空 = 不限');
            $t->tinyInteger('status')->default(1)->comment('1 启用 0 停用');
            $t->integer('sort')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['status', 'id'], 'idx_status');
        });

        Schema::create('bundle_item', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('bundle_id');
            $t->unsignedBigInteger('goods_id');
            $t->unsignedBigInteger('sku_id')->comment('套餐绑定具体规格');
            $t->integer('sort')->default(0);
            $t->unique(['bundle_id', 'sku_id'], 'uk_bundle_sku');
            $t->index('goods_id', 'idx_goods');
            $t->index('sku_id', 'idx_sku');
        });

        Schema::table('order', function (Blueprint $t) {
            $t->decimal('bundle_amount', 12, 2)->default(0)->comment('套餐优惠金额')->after('coupon_amount');
            $t->text('bundle_info')->nullable()->comment('用了哪些套餐的快照 JSON：[{id,name,sets,amount}]')->after('bundle_amount');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $t) {
            $t->dropColumn(['bundle_amount', 'bundle_info']);
        });
        Schema::dropIfExists('bundle_item');
        Schema::dropIfExists('bundle');
    }
};
