<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 分销员：申请、审核，以及佣金账户（金额均为基准币种）
        Schema::create('distributor', function (Blueprint $t) {
            $t->unsignedBigInteger('member_id')->primary();
            $t->tinyInteger('status')->default(0)->comment('0待审核 1正常 2已拒绝 3已禁用');
            $t->string('real_name', 60)->default('');
            $t->string('apply_remark', 255)->default('');
            $t->string('reject_reason', 255)->default('');
            $t->decimal('pending_amount', 12, 2)->default(0)->comment('待结算佣金');
            $t->decimal('available_amount', 12, 2)->default(0)->comment('可提现佣金');
            $t->decimal('frozen_amount', 12, 2)->default(0)->comment('提现中');
            $t->decimal('withdrawn_amount', 12, 2)->default(0)->comment('已提现');
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->index(['status']);
        });

        // 佣金明细：一笔订单每个受益层级一行
        Schema::create('distribution_commission', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('buyer_id');
            $t->unsignedBigInteger('member_id')->comment('受益分销员');
            $t->tinyInteger('level')->comment('1一级 2二级');
            $t->decimal('base_amount', 12, 2)->comment('计佣基数（基准币种）');
            $t->decimal('rate', 5, 2)->comment('佣金比例%');
            $t->decimal('amount', 12, 2)->comment('当前佣金（退款后会减少）');
            $t->tinyInteger('status')->default(0)->comment('0待结算 1已结算 2已作废');
            $t->dateTime('settled_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['order_id', 'level'], 'uk_order_level');
            $t->index(['member_id', 'status'], 'idx_member');
            $t->index(['status'], 'idx_status');
        });

        // 提现申请
        Schema::create('distribution_withdraw', function (Blueprint $t) {
            $t->id();
            $t->string('withdraw_no', 32)->unique();
            $t->unsignedBigInteger('member_id');
            $t->decimal('amount', 12, 2);
            $t->string('method', 20)->comment('bank/paypal/alipay/wechat/other');
            $t->text('account_info')->nullable()->comment('收款信息（加密）');
            $t->tinyInteger('status')->default(0)->comment('0待审核 1待打款 2已打款 3已拒绝');
            $t->string('reject_reason', 255)->default('');
            $t->string('pay_remark', 255)->default('')->comment('打款凭证/流水号');
            $t->unsignedInteger('admin_id')->default(0);
            $t->dateTime('paid_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->index(['member_id'], 'idx_member');
            $t->index(['status'], 'idx_status');
        });

        // 佣金账户流水（可提现余额的每一次变动）
        Schema::create('distribution_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('member_id');
            $t->tinyInteger('type')->comment('1佣金结算 2退款扣回 3提现申请 4提现驳回退还 5后台调整');
            $t->decimal('change_amount', 12, 2);
            $t->decimal('after_amount', 12, 2)->comment('变动后可提现余额');
            $t->string('ref_no', 64)->default('');
            $t->string('remark', 255)->default('');
            $t->dateTime('created_at')->nullable();
            $t->index(['member_id'], 'idx_member');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_log');
        Schema::dropIfExists('distribution_withdraw');
        Schema::dropIfExists('distribution_commission');
        Schema::dropIfExists('distributor');
    }
};
