<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 商品可以单独设置分销佣金比例，或不参与分销
        Schema::table('goods', function (Blueprint $t) {
            $t->tinyInteger('commission_type')->default(0)->after('shipping_template_id')->comment('分销佣金：0跟随全局 1单独设置 2不参与分销');
            $t->decimal('commission_rate_1', 5, 2)->default(0)->after('commission_type')->comment('一级佣金比例%（单独设置时生效）');
            $t->decimal('commission_rate_2', 5, 2)->default(0)->after('commission_rate_1')->comment('二级佣金比例%（单独设置时生效）');
        });

        // 佣金明细记下生成时的原始佣金：一张订单里商品比例不同时，不能再用 基数 × 比例 反推，退款重算要用它
        Schema::table('distribution_commission', function (Blueprint $t) {
            $t->decimal('origin_amount', 12, 2)->default(0)->after('rate')->comment('生成时的原始佣金');
        });
        DB::table('distribution_commission')->update(['origin_amount' => DB::raw('ROUND(base_amount * rate / 100, 2)')]);
    }

    public function down(): void
    {
        Schema::table('distribution_commission', function (Blueprint $t) {
            $t->dropColumn('origin_amount');
        });
        Schema::table('goods', function (Blueprint $t) {
            $t->dropColumn(['commission_type', 'commission_rate_1', 'commission_rate_2']);
        });
    }
};
