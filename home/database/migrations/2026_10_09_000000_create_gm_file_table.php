<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 上传文件记录表（前缀 gm_ 自动补全 => gm_file）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 30)->default('')->comment('存储驱动 local/aliyun/...');
            $table->string('scene', 30)->default('other')->comment('用途 goods/avatar/banner/editor/setting/other');
            $table->string('path', 500)->comment('存储桶内相对路径');
            $table->string('name', 255)->default('')->comment('原始文件名');
            $table->string('ext', 10)->default('');
            $table->string('mime', 100)->default('');
            $table->unsignedBigInteger('size')->default(0)->comment('字节');
            $table->char('md5', 32)->default('')->index();
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);
            $table->unsignedInteger('admin_id')->default(0)->index();
            $table->dateTime('created_at')->nullable();
            $table->comment('上传文件记录');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file');
    }
};
