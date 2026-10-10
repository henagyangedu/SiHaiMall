<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 电子面单的快递账号：每家快递一条（月结账号、密码、网点、面单模板）
        Schema::create('waybill_account', function (Blueprint $t) {
            $t->id();
            $t->string('express_code', 20)->comment('快递公司编码（和发货用的一致，如 SF、ZTO）');
            $t->string('partner_id', 100)->default('')->comment('月结账号 / 客户号');
            $t->text('partner_key')->nullable()->comment('账号密码（加密保存）');
            $t->string('net', 100)->default('')->comment('网点名称（部分快递需要）');
            $t->string('temp_id', 100)->default('')->comment('面单模板编号');
            $t->string('remark', 100)->default('');
            $t->tinyInteger('status')->default(1)->comment('1 启用 0 停用');
            $t->timestamps();
            $t->unique('express_code', 'uk_express');
        });

        Schema::table('shipping', function (Blueprint $t) {
            $t->string('waybill_task_id', 64)->default('')->comment('电子面单的任务号（手填单号发货的为空）')->after('express_no');
            $t->string('label_url', 500)->default('')->comment('面单文件地址')->after('waybill_task_id');
        });
    }

    public function down(): void
    {
        Schema::table('shipping', function (Blueprint $t) {
            $t->dropColumn(['waybill_task_id', 'label_url']);
        });
        Schema::dropIfExists('waybill_account');
    }
};
