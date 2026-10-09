<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 销售区域：国内按省/市划分，境外按国家划分；区域价按该区域的定价币种填写
        Schema::create('sale_region', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name', 100);
            $t->tinyInteger('scope')->default(1)->comment('1国内(省市) 2境外(国家)');
            $t->string('currency', 10)->comment('该区域的定价币种');
            $t->integer('sort')->default(0);
            $t->tinyInteger('status')->default(1);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
        });

        // 区域覆盖的地区：同一地区只能属于一个区域
        Schema::create('sale_region_area', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('region_id');
            $t->string('country_code', 2);
            $t->string('province', 60)->default('')->comment('国内必填');
            $t->string('city', 60)->default('')->comment('空=整个省');
            $t->unique(['country_code', 'province', 'city'], 'uk_area');
            $t->index(['region_id'], 'idx_region');
        });

        // SKU 区域价（区域的定价币种）
        Schema::create('goods_region_price', function (Blueprint $t) {
            $t->unsignedBigInteger('sku_id');
            $t->unsignedInteger('region_id');
            $t->decimal('price', 12, 2);
            $t->primary(['sku_id', 'region_id']);
            $t->index(['region_id'], 'idx_region');
        });

        // 订单记录下单时命中的销售区域（快照）
        Schema::table('order', function (Blueprint $t) {
            $t->unsignedInteger('price_region_id')->default(0)->comment('下单时命中的销售区域，0=标准价');
            $t->string('price_region_name', 100)->default('');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $t) {
            $t->dropColumn(['price_region_id', 'price_region_name']);
        });
        Schema::dropIfExists('goods_region_price');
        Schema::dropIfExists('sale_region_area');
        Schema::dropIfExists('sale_region');
    }
};
