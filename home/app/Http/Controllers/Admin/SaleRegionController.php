<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\SaleRegionSaveRequest;
use App\Http\Requests\Admin\StatusRequest;
use App\Services\Admin\SaleRegionService;
use App\Services\Common\SaleRegionService as RegionMatcher;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class SaleRegionController extends BaseAdminController
{
    public function __construct(private SaleRegionService $service, private RegionMatcher $matcher) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['keyword', 'scope'])));
    }

    public function options()
    {
        return ApiResponse::ok($this->service->options());
    }

    /** 中国大陆省 → 市 标准名单 */
    public function cnDivisions()
    {
        return ApiResponse::ok($this->matcher->cnDivisions());
    }

    public function store(SaleRegionSaveRequest $request)
    {
        $region = $this->service->create($request->validated());

        return ApiResponse::ok(['id' => $region->id]);
    }

    public function update(SaleRegionSaveRequest $request)
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
