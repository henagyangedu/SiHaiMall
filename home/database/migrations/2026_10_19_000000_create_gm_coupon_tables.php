<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 优惠券：满减券和折扣券
        Schema::create('coupon', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->comment('券名称（后台和买家都能看到）');
            $t->tinyInteger('type')->comment('1 满减 2 折扣');
            $t->decimal('threshold', 12, 2)->default(0)->comment('使用门槛（适用商品金额满多少），0 为无门槛');
            $t->decimal('amount', 12, 2)->default(0)->comment('满减金额（type=1）');
            $t->decimal('discount', 5, 2)->default(0)->comment('折扣百分比，90 表示 9 折（type=2）');
            $t->decimal('max_amount', 12, 2)->default(0)->comment('折扣券最多减多少，0 不限');
            $t->tinyInteger('scope')->default(1)->comment('1 全部商品 2 指定分类 3 指定商品');
            $t->text('scope_ids')->nullable()->comment('分类或商品 ID 的 JSON 数组');
            $t->integer('total')->default(0)->comment('发行总量，0 不限');
            $t->integer('received')->default(0)->comment('已领取数量');
            $t->integer('per_member')->default(1)->comment('每人限领');
            $t->tinyInteger('valid_type')->default(1)->comment('1 固定时间段 2 领取后 N 天');
            $t->dateTime('valid_start')->nullable();
            $t->dateTime('valid_end')->nullable();
            $t->integer('valid_days')->default(0);
            $t->dateTime('receive_start')->nullable()->comment('可领取的开始时间，空为不限');
            $t->dateTime('receive_end')->nullable();
            $t->tinyInteger('is_public')->default(1)->comment('1 买家可自己领 0 只能后台发放');
            $t->tinyInteger('status')->default(1)->comment('1 启用 0 停用');
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->index(['status', 'is_public'], 'idx_status');
        });

        // 会员领到的优惠券：有效期在领取时固化，之后改券的设置不影响已经领到手的
        Schema::create('member_coupon', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('member_id');
            $t->unsignedBigInteger('coupon_id');
            $t->tinyInteger('status')->default(0)->comment('0 未使用 1 已使用 2 已过期');
            $t->dateTime('valid_start')->comment('领取时固化的有效期');
            $t->dateTime('valid_end');
            $t->unsignedBigInteger('order_id')->default(0)->comment('用在了哪张订单');
            $t->dateTime('used_at')->nullable();
            $t->tinyInteger('source')->default(1)->comment('1 自己领取 2 后台发放');
            $t->dateTime('created_at')->nullable();
            $t->index(['member_id', 'status', 'valid_end'], 'idx_member');
            $t->index('coupon_id', 'idx_coupon');
            $t->index('order_id', 'idx_order');
        });

        // 订单上固化优惠券抵了多少：商品 + 运费 - 会员折扣 - 优惠券 - 积分 = 应付
        Schema::table('order', function (Blueprint $t) {
            $t->decimal('coupon_amount', 12, 2)->default(0)->after('points_amount')->comment('优惠券抵扣金额');
            $t->unsignedBigInteger('member_coupon_id')->default(0)->after('coupon_amount')->comment('使用的会员优惠券');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $t) {
            $t->dropColumn(['coupon_amount', 'member_coupon_id']);
        });
        Schema::dropIfExists('member_coupon');
        Schema::dropIfExists('coupon');
    }
};
