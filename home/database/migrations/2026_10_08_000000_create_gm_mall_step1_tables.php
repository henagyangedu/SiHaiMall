<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 第一步建表：直接执行 database/sql/gm_mall_step1.sql。
 * 注意：SQL 里表名已带 gm_ 前缀，DB::unprepared 不会再加前缀；
 * Eloquent 通过 config/database.php 的 prefix=gm_ 访问同名表，两者一致。
 * 后续新增/修改表请写新的标准 migration，不要再改这个文件。
 */
return new class extends Migration
{
    private function sqlPath(): string
    {
        return database_path('sql/gm_mall_step1.sql');
    }

    public function up(): void
    {
        DB::unprepared(file_get_contents($this->sqlPath()));
    }

    public function down(): void
    {
        preg_match_all('/CREATE TABLE `(\w+)`/', file_get_contents($this->sqlPath()), $m);
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (array_reverse($m[1]) as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
