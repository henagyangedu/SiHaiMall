<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\BundleSaveRequest;
use App\Http\Requests\Admin\StatusRequest;
use App\Services\Admin\BundleService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 搭配套餐 */
class BundleController extends BaseAdminController
{
    public function __construct(private BundleService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['keyword', 'status', 'size'])));
    }

    /** 选商品用：按名称找在售商品的规格 */
    public function skus(Request $request)
    {
        return ApiResponse::ok($this->service->skuOptions((string) $request->query('keyword', '')));
    }

    public function store(BundleSaveRequest $request)
    {
        return ApiResponse::ok(['id' => $this->service->create($request->validated())->id]);
    }

    public function update(BundleSaveRequest $request)
    {
        $this->service->update((int) $request->route('id'), $request->validated());

        return ApiResponse::ok();
    }

    public function status(StatusRequest $request)
    {
        $this->service->setStatus((int) $request->route('id'), (int) $request->validated('status'));

        return ApiResponse::ok();
    }

    public function destroy(Request $request)
    {
        $this->service->delete((int) $request->route('id'));

        return ApiResponse::ok();
    }
}
