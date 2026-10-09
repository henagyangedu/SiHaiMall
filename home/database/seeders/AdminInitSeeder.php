<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** 初始化：同步菜单 + 创建超级管理员 admin（密码取 ADMIN_INIT_PASSWORD，未设置则随机生成并在终端显示一次） */
class AdminInitSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(MenuSeeder::class);

        if (Admin::withTrashed()->where('username', 'admin')->exists()) {
            $this->command?->warn('管理员 admin 已存在，跳过创建');

            return;
        }

        $password = config('admin.init_password') ?: Str::password(length: 14, symbols: false);

        Admin::query()->create([
            'username' => 'admin',
            'password' => Hash::make($password),
            'nickname' => '超级管理员',
            'role_id'  => 0,
            'status'   => 1,
        ]);

        $this->command?->info('超级管理员已创建  账号：admin  密码：' . $password);
        $this->command?->warn('请立即登录并修改密码，此密码不会再次显示。');
    }
}
