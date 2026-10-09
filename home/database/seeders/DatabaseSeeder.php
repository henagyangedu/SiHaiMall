<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** 首次部署执行 php artisan db:seed：同步菜单权限并创建超级管理员（可重复执行，已有 admin 时只同步菜单） */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminInitSeeder::class);
    }
}
