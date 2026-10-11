<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 开放接口的应用：一个外部系统（ERP、财务、物流）一条，各有一把密钥
        Schema::create('open_app', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->comment('应用名称，如“金蝶 ERP”');
            $t->string('key_prefix', 12)->comment('密钥开头几位，列表里用来辨认');
            $t->char('key_hash', 64)->comment('密钥的 sha256，不存明文');
            $t->json('scopes')->comment('权限列表');
            $t->string('ip_whitelist', 500)->default('')->comment('允许的 IP，逗号分隔，空 = 不限');
            $t->tinyInteger('status')->default(1)->comment('1 启用 0 停用');
            $t->string('remark', 200)->default('');
            $t->dateTime('last_used_at')->nullable();
            $t->timestamps();
            $t->unique('key_hash', 'uk_key_hash');
        });

        // 写操作的调用记录（谁在什么时候发了哪张单的货、改了哪个库存），查问题用
        Schema::create('open_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('app_id');
            $t->string('method', 8);
            $t->string('path', 120);
            $t->string('ip', 45)->default('');
            $t->integer('code')->default(0)->comment('返回的业务码');
            $t->string('summary', 500)->default('')->comment('请求摘要，不含密钥');
            $t->dateTime('created_at')->nullable();
            $t->index(['app_id', 'created_at'], 'idx_app_time');
        });

        // 增量拉取要按更新时间查
        Schema::table('order', function (Blueprint $t) {
            $t->index('updated_at', 'idx_updated');
        });
        Schema::table('pay_trade', function (Blueprint $t) {
            $t->index('updated_at', 'idx_updated');
        });
    }

    public function down(): void
    {
        Schema::table('pay_trade', function (Blueprint $t) {
            $t->dropIndex('idx_updated');
        });
        Schema::table('order', function (Blueprint $t) {
            $t->dropIndex('idx_updated');
        });
        Schema::dropIfExists('open_log');
        Schema::dropIfExists('open_app');
    }
};
