<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 订单发票：买家付款后申请，商家在自己的开票软件里开好，再把发票号码和文件登记上来。一张订单一张
        Schema::create('order_invoice', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->string('order_no', 32);
            $t->unsignedBigInteger('member_id');
            $t->tinyInteger('title_type')->default(1)->comment('抬头类型：1 个人 2 企业');
            $t->string('title', 100)->comment('发票抬头');
            $t->string('tax_no', 30)->default('')->comment('纳税人识别号（企业必填）');
            $t->string('email', 100)->default('')->comment('收票邮箱');
            $t->text('company_info')->nullable()->comment('企业的注册地址、电话、开户行、账号（JSON，选填）');
            $t->decimal('amount', 12, 2)->default(0)->comment('开票金额');
            $t->tinyInteger('status')->default(0)->comment('0 待开票 1 已开票 2 已驳回 3 已作废');
            $t->string('invoice_no', 40)->default('')->comment('发票号码');
            $t->string('file_url', 255)->default('')->comment('发票文件（PDF 或图片）');
            $t->string('reject_reason', 200)->default('')->comment('驳回或作废的原因');
            $t->unsignedBigInteger('admin_id')->default(0)->comment('处理人');
            $t->dateTime('issued_at')->nullable();
            $t->timestamps();
            $t->unique('order_id', 'uk_order');
            $t->index(['member_id', 'id'], 'idx_member');
            $t->index(['status', 'id'], 'idx_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_invoice');
    }
};
