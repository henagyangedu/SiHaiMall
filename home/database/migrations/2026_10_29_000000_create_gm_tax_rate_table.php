<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 税率规则：按收货地址的国家（可细到省 / 州）收税，一个地区一条
        Schema::create('tax_rate', function (Blueprint $t) {
            $t->id();
            $t->string('country_code', 8)->comment('国家码');
            $t->string('province', 100)->default('')->comment('省 / 州，空 = 整个国家');
            $t->string('name', 50)->comment('税名，如 VAT、Sales Tax，显示给买家');
            $t->decimal('rate', 6, 3)->comment('税率百分数，如 7.250');
            $t->tinyInteger('included')->default(0)->comment('0 价外税（另加），1 价内税（标价已含）');
            $t->tinyInteger('shipping_taxable')->default(0)->comment('运费是否计税');
            $t->tinyInteger('status')->default(1)->comment('1 启用 0 停用');
            $t->timestamps();
            $t->unique(['country_code', 'province'], 'uk_area');
        });

        // 订单上固化下单时的税：之后改税率不影响已下的订单
        Schema::table('order', function (Blueprint $t) {
            $t->decimal('tax_amount', 12, 2)->default(0)->comment('税额（下单币种）')->after('bundle_info');
            $t->decimal('tax_shipping', 12, 2)->default(0)->comment('其中运费部分的税')->after('tax_amount');
            $t->decimal('tax_rate', 6, 3)->default(0)->comment('下单时的税率百分数')->after('tax_shipping');
            $t->string('tax_name', 50)->default('')->comment('下单时的税名')->after('tax_rate');
            $t->tinyInteger('tax_included')->default(0)->comment('1 = 税含在价格里，不另加')->after('tax_name');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $t) {
            $t->dropColumn(['tax_amount', 'tax_shipping', 'tax_rate', 'tax_name', 'tax_included']);
        });
        Schema::dropIfExists('tax_rate');
    }
};
