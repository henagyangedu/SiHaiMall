<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/** 会员模块补充：余额流水表、积分流水加操作人、等级序号唯一、默认等级初始数据 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('member_balance_log')) {
            Schema::create('member_balance_log', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('member_id')->index();
                $t->tinyInteger('type')->comment('1后台调整 2充值 3消费 4退款');
                $t->decimal('change_amount', 12, 2);
                $t->decimal('after_amount', 12, 2);
                $t->string('ref_no', 64)->default('');
                $t->string('remark', 255)->default('');
                $t->unsignedInteger('admin_id')->default(0)->comment('后台操作人，0=系统');
                $t->dateTime('created_at')->nullable();
            });
        }

        if (!Schema::hasColumn('member_point_log', 'admin_id')) {
            Schema::table('member_point_log', function (Blueprint $t) {
                $t->unsignedInteger('admin_id')->default(0)->after('remark')->comment('后台操作人，0=系统');
            });
        }

        // 等级序号唯一（表为空或无重复时才加）
        $dup = DB::table('member_level')->select('level_no')->groupBy('level_no')->havingRaw('count(*) > 1')->exists();
        if (!$dup) {
            try {
                Schema::table('member_level', function (Blueprint $t) {
                    $t->unique('level_no', 'uk_level_no');
                });
            } catch (\Throwable $e) {
                // 已存在则忽略
            }
        }

        // 默认等级：会员表 level_id 默认 1，必须有这条
        if (!DB::table('member_level')->where('id', 1)->exists()) {
            DB::table('member_level')->insert(['id' => 1, 'level_no' => 1, 'icon' => '', 'need_points' => 0, 'discount' => 100, 'status' => 1]);
            DB::table('member_level_lang')->insert([
                ['level_id' => 1, 'lang' => 'zh-CN', 'name' => '普通会员'],
                ['level_id' => 1, 'lang' => 'en', 'name' => 'Member'],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('member_balance_log');
    }
};
