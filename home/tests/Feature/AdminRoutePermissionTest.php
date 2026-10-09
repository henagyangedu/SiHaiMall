<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminMenu;
use App\Models\AdminToken;
use Database\Seeders\MenuSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\FeatureTestCase;

/**
 * 后台接口和权限点的结构性检查：新增接口忘了挂权限、权限点拼错、登记了却没人用，这里都会失败。
 */
class AdminRoutePermissionTest extends FeatureTestCase
{
    /** 只要求登录、不需要权限点的接口：登录本身、看自己的信息、退出、改自己的密码、上传图片 */
    private const LOGIN_ONLY = ['auth/info', 'auth/logout', 'auth/password', 'upload/image'];

    /** 不需要登录的接口 */
    private const PUBLIC = ['auth/login'];

    /** 路由上的特殊标记：只有超级管理员能做，不是可以授予角色的权限点 */
    private const SUPER_ONLY = 'super';

    /** @return array<int,array{uri:string,auth:bool,perms:string[]}> */
    private function adminRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            if (! str_starts_with($r->uri(), 'admin-api/')) {
                continue;
            }
            $mw = array_filter($r->gatherMiddleware(), 'is_string');
            $perms = [];
            foreach ($mw as $m) {
                if (str_starts_with($m, 'perm:')) {
                    $perms = array_merge($perms, array_map('trim', explode(',', substr($m, 5))));
                }
            }
            $out[] = ['uri' => substr($r->uri(), strlen('admin-api/')), 'auth' => in_array('admin.auth', $mw, true), 'perms' => $perms];
        }

        return $out;
    }

    public function test_every_admin_route_requires_login_and_a_permission(): void
    {
        $routes = $this->adminRoutes();
        $this->assertGreaterThan(100, count($routes));

        foreach ($routes as $r) {
            if (in_array($r['uri'], self::PUBLIC, true)) {
                continue;
            }
            $this->assertTrue($r['auth'], "后台接口 {$r['uri']} 没有登录校验");
            if (! in_array($r['uri'], self::LOGIN_ONLY, true)) {
                $this->assertNotEmpty($r['perms'], "后台接口 {$r['uri']} 没有挂权限点（确实不需要的话加到 LOGIN_ONLY 并说明原因）");
            }
        }
    }

    public function test_route_permissions_and_the_menu_definition_match(): void
    {
        $this->seed(MenuSeeder::class);
        $defined = AdminMenu::query()->where('perm', '!=', '')->pluck('perm')->unique()->all();
        $used = array_values(array_unique(array_merge(...array_column($this->adminRoutes(), 'perms'))));

        // 路由用到的权限点必须在菜单里登记过，否则没有任何角色能被授予它
        $this->assertSame([], array_values(array_diff($used, $defined, [self::SUPER_ONLY])), '路由上有菜单里没登记的权限点（拼错了，或忘了加到 MenuSeeder）');
        // 菜单里登记的权限点必须有接口在用，否则角色编辑页会出现勾了也没用的选项
        $this->assertSame([], array_values(array_diff($defined, $used)), '菜单里有没有任何接口使用的权限点');
    }

    public function test_obsolete_permissions_are_removed_from_existing_installs(): void
    {
        $this->seed(MenuSeeder::class);
        // 模拟旧库：之前登记过、现在不用了的权限点，而且已经授给了某个角色
        $parent = (int) AdminMenu::query()->where('perm', 'setting:log:view')->value('id');
        $old = AdminMenu::query()->create(['pid' => $parent, 'type' => 3, 'title_key' => '导出', 'perm' => 'setting:log:export', 'path' => '', 'component' => '', 'icon' => '', 'api_rule' => '', 'sort' => 10, 'visible' => 1, 'status' => 1]);
        DB::table('admin_role_menu')->insert(['role_id' => 99, 'menu_id' => $old->id]);
        $total = AdminMenu::query()->count();

        $this->seed(MenuSeeder::class);

        $this->assertNull(AdminMenu::query()->find($old->id));
        $this->assertSame(0, DB::table('admin_role_menu')->where('menu_id', $old->id)->count());
        $this->assertSame($total - 1, AdminMenu::query()->count(), '其他菜单不受影响');
    }

    public function test_an_admin_without_the_permission_is_rejected_and_one_with_it_is_let_in(): void
    {
        $this->seed(MenuSeeder::class);
        $roleId = (int) DB::table('admin_role')->insertGetId(['name' => '只能看订单', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_role_menu')->insert(['role_id' => $roleId, 'menu_id' => AdminMenu::query()->where('perm', 'order:list:view')->value('id')]);
        $admin = Admin::query()->create(['username' => 'limited', 'password' => Hash::make('Secret#123'), 'nickname' => '受限管理员', 'role_id' => $roleId, 'status' => 1]);
        $token = Str::random(64);
        AdminToken::query()->create(['admin_id' => $admin->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'last_used_at' => now(), 'ip' => '127.0.0.1', 'user_agent' => 'test']);
        $as = fn (string $method, string $uri, array $data = []) => $this->json($method, '/admin-api'.$uri, $data, ['Authorization' => 'Bearer '.$token]);

        $as('GET', '/order/list')->assertJsonPath('code', 0);
        // 没授权的：看会员、改交易设置、导出订单、改支付配置、管理菜单（只有超管能做）
        foreach ([['GET', '/member/list'], ['PUT', '/config/trade'], ['GET', '/order/list/export'], ['GET', '/driver/pay'], ['POST', '/menu']] as [$method, $uri]) {
            $this->assertSame(403, $as($method, $uri)->json('code'), "受限管理员不应该能 {$method} {$uri}");
        }
        // 不带令牌
        $this->json('GET', '/admin-api/order/list')->assertJsonPath('code', 401);
    }
}
