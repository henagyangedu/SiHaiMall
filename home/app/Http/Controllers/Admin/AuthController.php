<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ChangePasswordRequest;
use App\Http\Requests\Admin\LoginRequest;
use App\Services\Admin\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AuthController extends BaseAdminController
{
    public function __construct(private AuthService $service) {}

    public function login(LoginRequest $request)
    {
        $data = $this->service->login(
            $request->validated('username'),
            $request->validated('password'),
            (string) $request->ip(),
            (string) $request->userAgent(),
        );

        return ApiResponse::ok($data);
    }

    public function info(Request $request)
    {
        return ApiResponse::ok($this->service->info($this->admin($request)));
    }

    public function logout(Request $request)
    {
        $this->service->logout((string) $request->bearerToken());

        return ApiResponse::ok();
    }

    public function password(ChangePasswordRequest $request)
    {
        $this->service->changePassword(
            $this->admin($request),
            $request->validated('old_password'),
            $request->validated('new_password'),
            (string) $request->bearerToken(),
        );

        return ApiResponse::ok(null, '密码已修改');
    }
}
