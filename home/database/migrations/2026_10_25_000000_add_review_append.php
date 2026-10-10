<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 追评：一条评价最多追加一次，和原评价放在同一行
        Schema::table('goods_review', function (Blueprint $t) {
            $t->string('append_content', 500)->default('')->comment('追评内容')->after('replied_at');
            $t->text('append_images')->nullable()->comment('追评图片地址 JSON 数组')->after('append_content');
            $t->dateTime('append_at')->nullable()->comment('追评时间；空 = 没有追评')->after('append_images');
            $t->string('append_reply', 500)->default('')->comment('商家对追评的回复')->after('append_at');
            $t->dateTime('append_replied_at')->nullable()->after('append_reply');
        });
    }

    public function down(): void
    {
        Schema::table('goods_review', function (Blueprint $t) {
            $t->dropColumn(['append_content', 'append_images', 'append_at', 'append_reply', 'append_replied_at']);
        });
    }
};
