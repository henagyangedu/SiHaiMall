<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\MenuSaveRequest;
use App\Services\Admin\MenuService;
use App\Support\ApiResponse;

class MenuController extends BaseAdminController
{
    public function __construct(private MenuService $service) {}

    public function tree()
    {
        return ApiResponse::ok($this->service->tree());
    }

    public function store(MenuSaveRequest $request)
    {
        $menu = $this->service->create($request->validated());

        return ApiResponse::ok(['id' => $menu->id]);
    }

    public function update(MenuSaveRequest $request, int $id)
    {
        $this->service->update($id, $request->validated());

        return ApiResponse::ok();
    }

    public function destroy(int $id)
    {
        $this->service->delete($id);

        return ApiResponse::ok();
    }
}
