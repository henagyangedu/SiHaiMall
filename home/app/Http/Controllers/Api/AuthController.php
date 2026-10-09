<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\PasswordRequest;
use App\Http\Requests\Api\ProfileRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Services\Api\MemberAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    public function __construct(private MemberAuthService $service) {}

    public function register(RegisterRequest $request)
    {
        return ApiResponse::ok($this->service->register(
            $request->validated() + ['lang' => $this->lang($request), 'currency' => $this->currency($request)],
            $this->source($request), (string) $request->ip(), (string) $request->userAgent()
        ));
    }

    public function login(LoginRequest $request)
    {
        return ApiResponse::ok($this->service->login(
            $request->validated('account'), $request->validated('password'),
            $this->source($request), (string) $request->ip(), (string) $request->userAgent()
        ));
    }

    public function logout(Request $request)
    {
        $this->service->logout((string) $request->bearerToken());

        return ApiResponse::ok();
    }

    public function profile(Request $request)
    {
        return ApiResponse::ok($this->service->profile($this->member($request)));
    }

    public function updateProfile(ProfileRequest $request)
    {
        return ApiResponse::ok($this->service->updateProfile($this->member($request), $request->validated()));
    }

    public function password(PasswordRequest $request)
    {
        $this->service->changePassword($this->member($request), $request->validated('old_password'), $request->validated('password'), (string) $request->bearerToken());

        return ApiResponse::ok();
    }
}
