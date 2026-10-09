<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\CategorySaveRequest;
use App\Http\Requests\Admin\StatusRequest;
use App\Services\Admin\CategoryService;
use App\Support\ApiResponse;

class CategoryController extends BaseAdminController
{
    public function __construct(private CategoryService $service) {}

    public function tree()
    {
        return ApiResponse::ok($this->service->tree());
    }

    public function store(CategorySaveRequest $request)
    {
        $cat = $this->service->create($request->validated());

        return ApiResponse::ok(['id' => $cat->id]);
    }

    public function update(CategorySaveRequest $request, int $id)
    {
        $this->service->update($id, $request->validated());

        return ApiResponse::ok();
    }

    public function status(StatusRequest $request, int $id)
    {
        $this->service->setStatus($id, (int) $request->validated('status'));

        return ApiResponse::ok();
    }

    public function destroy(int $id)
    {
        $this->service->delete($id);

        return ApiResponse::ok();
    }
}
