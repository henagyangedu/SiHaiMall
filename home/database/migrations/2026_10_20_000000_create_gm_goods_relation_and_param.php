<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 商品的配套商品（设备 → 耗材、配件）。只存一个方向，“适用设备”是反过来查出来的
        Schema::create('goods_relation', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('goods_id')->comment('主商品（如设备）');
            $t->unsignedBigInteger('related_goods_id')->comment('配套的商品（如耗材、配件）');
            $t->integer('sort')->default(0);
            $t->dateTime('created_at')->nullable();
            $t->unique(['goods_id', 'related_goods_id'], 'uk_pair');
            $t->index('related_goods_id', 'idx_related');
        });

        // 商品规格参数：每个商品自己填名称和值（如“粮桶容量 / 5L”），多语言
        Schema::create('goods_param', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('goods_id');
            $t->integer('sort')->default(0);
            $t->index(['goods_id', 'sort'], 'idx_goods');
        });
        Schema::create('goods_param_lang', function (Blueprint $t) {
            $t->unsignedBigInteger('param_id');
            $t->string('lang', 10);
            $t->string('name', 100)->default('')->comment('参数名称，如“粮桶容量”');
            $t->string('value', 500)->default('')->comment('参数值，如“5L”');
            $t->primary(['param_id', 'lang']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_param_lang');
        Schema::dropIfExists('goods_param');
        Schema::dropIfExists('goods_relation');
    }
};
