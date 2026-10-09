<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\BrandSaveRequest;
use App\Http\Requests\Admin\StatusRequest;
use App\Services\Admin\BrandService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class BrandController extends BaseAdminController
{
    public function __construct(private BrandService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['keyword', 'status', 'size'])));
    }

    public function options()
    {
        return ApiResponse::ok($this->service->options());
    }

    public function store(BrandSaveRequest $request)
    {
        $brand = $this->service->create($request->validated());

        return ApiResponse::ok(['id' => $brand->id]);
    }

    public function update(BrandSaveRequest $request, int $id)
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
