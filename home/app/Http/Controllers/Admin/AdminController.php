<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\AdminSaveRequest;
use App\Http\Requests\Admin\ResetPasswordRequest;
use App\Services\Admin\AdminUserService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminController extends BaseAdminController
{
    public function __construct(private AdminUserService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['keyword', 'status', 'size'])));
    }

    public function store(AdminSaveRequest $request)
    {
        $admin = $this->service->create($request->validated(), $this->admin($request));

        return ApiResponse::ok(['id' => $admin->id]);
    }

    public function update(AdminSaveRequest $request, int $id)
    {
        $this->service->update($id, $request->validated(), $this->admin($request));

        return ApiResponse::ok();
    }

    public function destroy(Request $request, int $id)
    {
        $this->service->delete($id, $this->admin($request));

        return ApiResponse::ok();
    }

    public function reset(ResetPasswordRequest $request, int $id)
    {
        $this->service->resetPassword($id, $request->validated('password'), $this->admin($request));

        return ApiResponse::ok(null, '密码已重置，该账号已强制下线');
    }
}
