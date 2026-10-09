<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 文章分类：文章、帮助中心各自一套（公告不分类），只有一级
        Schema::create('article_category', function (Blueprint $t) {
            $t->increments('id');
            $t->tinyInteger('type')->comment('1文章 3帮助');
            $t->integer('sort')->default(0);
            $t->tinyInteger('status')->default(1);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->index(['type', 'sort'], 'idx_type');
        });
        Schema::create('article_category_lang', function (Blueprint $t) {
            $t->unsignedInteger('category_id');
            $t->string('lang', 10);
            $t->string('name', 100);
            $t->primary(['category_id', 'lang']);
        });

        // 文章 / 公告 / 帮助
        Schema::create('article', function (Blueprint $t) {
            $t->id();
            $t->tinyInteger('type')->comment('1文章 2公告 3帮助');
            $t->unsignedInteger('category_id')->default(0);
            $t->string('cover', 255)->default('');
            $t->string('author', 60)->default('');
            $t->tinyInteger('is_top')->default(0);
            $t->integer('sort')->default(0);
            $t->tinyInteger('status')->default(1)->comment('1发布 0草稿');
            $t->unsignedInteger('views')->default(0);
            $t->dateTime('published_at')->nullable()->comment('到这个时间才对外显示');
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->index(['type', 'status', 'published_at'], 'idx_list');
            $t->index(['category_id'], 'idx_category');
        });
        Schema::create('article_lang', function (Blueprint $t) {
            $t->unsignedBigInteger('article_id');
            $t->string('lang', 10);
            $t->string('title', 255);
            $t->string('summary', 500)->default('');
            $t->mediumText('content')->nullable();
            $t->string('seo_title', 255)->default('');
            $t->string('seo_desc', 500)->default('');
            $t->primary(['article_id', 'lang']);
        });

        // 广告位
        Schema::create('ad_position', function (Blueprint $t) {
            $t->increments('id');
            $t->string('code', 50)->unique()->comment('前端按编码取，如 home_banner');
            $t->string('name', 100);
            $t->string('remark', 255)->default('')->comment('建议尺寸等说明');
            $t->tinyInteger('status')->default(1);
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
        });

        // 广告（轮播图就是“首页轮播”广告位下的广告）
        Schema::create('ad', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('position_id');
            $t->string('link_type', 20)->default('none')->comment('none/goods/category/article/url');
            $t->string('link_value', 500)->default('');
            $t->integer('sort')->default(0);
            $t->tinyInteger('status')->default(1);
            $t->dateTime('start_at')->nullable();
            $t->dateTime('end_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->index(['position_id', 'status', 'sort'], 'idx_position');
        });
        Schema::create('ad_lang', function (Blueprint $t) {
            $t->unsignedInteger('ad_id');
            $t->string('lang', 10);
            $t->string('title', 255)->default('');
            $t->string('image', 255)->default('');
            $t->primary(['ad_id', 'lang']);
        });

        // 预置广告位
        $now = now();
        DB::table('ad_position')->insert([
            ['code' => 'home_banner', 'name' => '首页轮播', 'remark' => '建议 750×360（H5 / 小程序），PC 端 1920×600', 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'home_middle', 'name' => '首页中部广告', 'remark' => '建议 710×200', 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'category_top', 'name' => '分类页顶部', 'remark' => '建议 710×240', 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        foreach (['ad_lang', 'ad', 'ad_position', 'article_lang', 'article', 'article_category_lang', 'article_category'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
