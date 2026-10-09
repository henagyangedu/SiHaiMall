<?php

namespace App\Http\Middleware;

use App\Services\Admin\MenuService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/**
 * 权限校验，用法：->middleware('perm:goods:list:edit')
 * 多个权限用逗号分隔表示"任一即可"：perm:setting:role:view,setting:admin:view
 * 特殊值 perm:super 表示仅超级管理员
 */
class AdminPerm
{
    public function __construct(private MenuService $menus) {}

    public function handle(Request $request, Closure $next, string ...$perms)
    {
        $admin = $request->attributes->get('admin');
        if (!$admin) {
            return ApiResponse::fail('未登录或登录已过期', 401);
        }

        $ok = $perms === ['super'] ? $admin->isSuper() : $this->menus->can($admin, $perms);

        if (!$ok) {
            return ApiResponse::fail('没有操作权限', 403);
        }

        return $next($request);
    }
}
