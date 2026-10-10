<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 注册时同意用户协议和隐私政策的时间（之前注册的、或当时后台没填协议的为空）
        Schema::table('member', function (Blueprint $t) {
            $t->dateTime('agreed_at')->nullable()->comment('同意用户协议和隐私政策的时间')->after('last_login_ip');
        });
    }

    public function down(): void
    {
        Schema::table('member', function (Blueprint $t) {
            $t->dropColumn('agreed_at');
        });
    }
};
