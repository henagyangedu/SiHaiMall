<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 商品收藏：一个会员对一个商品最多一条
        Schema::create('member_favorite', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('member_id');
            $t->unsignedBigInteger('goods_id');
            $t->dateTime('created_at')->nullable();
            $t->unique(['member_id', 'goods_id'], 'uk_member_goods');
            $t->index('goods_id', 'idx_goods');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_favorite');
    }
};
