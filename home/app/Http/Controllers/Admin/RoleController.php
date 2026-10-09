<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\RoleSaveRequest;
use App\Services\Admin\RoleService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class RoleController extends BaseAdminController
{
    public function __construct(private RoleService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['size'])));
    }

    public function options()
    {
        return ApiResponse::ok($this->service->options());
    }

    public function show(int $id)
    {
        return ApiResponse::ok($this->service->detail($id));
    }

    public function store(RoleSaveRequest $request)
    {
        $role = $this->service->create($request->validated(), $this->admin($request));

        return ApiResponse::ok(['id' => $role->id]);
    }

    public function update(RoleSaveRequest $request, int $id)
    {
        $this->service->update($id, $request->validated(), $this->admin($request));

        return ApiResponse::ok();
    }

    public function destroy(int $id)
    {
        $this->service->delete($id);

        return ApiResponse::ok();
    }
}
