<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AI 翻译：术语表 gm_translate_glossary、用量日志 gm_translate_log（前缀 gm_ 自动补全）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translate_glossary', function (Blueprint $table) {
            $table->id();
            $table->string('term', 200)->comment('原文术语（默认语言），如品牌名、专有名词');
            $table->string('lang', 10)->comment('目标语言');
            $table->string('translation', 200)->comment('固定译法');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['term', 'lang'], 'uk_term_lang');
            $table->comment('AI 翻译术语表');
        });

        Schema::create('translate_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('admin_id')->default(0)->index();
            $table->string('driver', 30)->default('');
            $table->string('model', 100)->default('');
            $table->string('from_lang', 10)->default('');
            $table->string('to_lang', 10)->default('');
            $table->unsignedInteger('input_chars')->default(0);
            $table->unsignedInteger('output_chars')->default(0);
            $table->unsignedTinyInteger('cached')->default(0)->comment('1=命中缓存，不产生模型调用');
            $table->unsignedTinyInteger('status')->default(1)->comment('1成功 0失败');
            $table->string('error', 255)->default('');
            $table->dateTime('created_at')->nullable()->index();
            $table->comment('AI 翻译用量日志');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translate_log');
        Schema::dropIfExists('translate_glossary');
    }
};
