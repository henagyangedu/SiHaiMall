<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 后台登录令牌表（表名前缀 gm_ 由 config/database.php 的 prefix 自动补全 => gm_admin_token）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_token', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('admin_id')->index();
            $table->char('token_hash', 64)->unique()->comment('令牌的sha256，不存明文');
            $table->dateTime('expires_at')->index();
            $table->dateTime('last_used_at')->nullable();
            $table->string('ip', 45)->default('');
            $table->string('user_agent', 255)->default('');
            $table->dateTime('created_at')->nullable();
            $table->comment('后台登录令牌');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_token');
    }
};
