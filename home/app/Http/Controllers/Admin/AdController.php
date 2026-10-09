<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\AdPositionSaveRequest;
use App\Http\Requests\Admin\AdSaveRequest;
use App\Http\Requests\Admin\StatusRequest;
use App\Services\Admin\AdService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdController extends BaseAdminController
{
    public function __construct(private AdService $service) {}

    // ---- 广告位
    public function positions()
    {
        return ApiResponse::ok($this->service->positions());
    }

    public function storePosition(AdPositionSaveRequest $request)
    {
        $p = $this->service->savePosition(0, $request->validated());

        return ApiResponse::ok(['id' => $p->id]);
    }

    public function updatePosition(AdPositionSaveRequest $request)
    {
        $this->service->savePosition((int) $request->route('id'), $request->validated());

        return ApiResponse::ok();
    }

    public function destroyPosition(Request $request)
    {
        $this->service->deletePosition((int) $request->route('id'));

        return ApiResponse::ok();
    }

    // ---- 广告
    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->ads((int) $request->query('position_id')));
    }

    public function store(AdSaveRequest $request)
    {
        $ad = $this->service->saveAd(0, $request->validated());

        return ApiResponse::ok(['id' => $ad->id]);
    }

    public function update(AdSaveRequest $request)
    {
        $this->service->saveAd((int) $request->route('id'), $request->validated());

        return ApiResponse::ok();
    }

    public function status(StatusRequest $request)
    {
        $this->service->setAdStatus((int) $request->route('id'), (int) $request->validated('status'));

        return ApiResponse::ok();
    }

    public function destroy(Request $request)
    {
        $this->service->deleteAd((int) $request->route('id'));

        return ApiResponse::ok();
    }
}
