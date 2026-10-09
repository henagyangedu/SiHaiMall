<?php

namespace App\Http\Middleware;

use App\Services\Admin\AuthService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/** 后台登录校验：Authorization: Bearer <token> */
class AdminAuth
{
    public function __construct(private AuthService $auth) {}

    public function handle(Request $request, Closure $next)
    {
        $plain = $request->bearerToken();
        $admin = $plain ? $this->auth->authenticate($plain) : null;

        if (!$admin) {
            return ApiResponse::fail('未登录或登录已过期', 401);
        }

        $request->attributes->set('admin', $admin);

        return $next($request);
    }
}
