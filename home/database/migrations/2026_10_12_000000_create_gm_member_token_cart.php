<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 会员登录令牌（只存 sha256 哈希，与后台令牌同一套机制）
        Schema::create('member_token', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('member_id');
            $t->char('token_hash', 64)->unique();
            $t->string('platform', 10)->default('');
            $t->dateTime('expires_at');
            $t->dateTime('last_used_at')->nullable();
            $t->string('ip', 45)->default('');
            $t->string('user_agent', 255)->default('');
            $t->dateTime('created_at')->nullable();
            $t->index(['member_id']);
            $t->index(['expires_at']);
        });

        // 购物车：按 SKU 存数量，价格始终实时计算
        Schema::create('cart', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('member_id');
            $t->unsignedBigInteger('sku_id');
            $t->unsignedInteger('qty')->default(1);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['member_id', 'sku_id'], 'uk_member_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart');
        Schema::dropIfExists('member_token');
    }
};
