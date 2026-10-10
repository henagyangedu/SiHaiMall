<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\WaybillAccountRequest;
use App\Services\Admin\WaybillService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 电子面单：快递账号的维护、发货弹窗的可选项 */
class WaybillController extends BaseAdminController
{
    public function __construct(private WaybillService $service) {}

    public function accounts()
    {
        return ApiResponse::ok(['list' => $this->service->accounts(), 'expresses' => $this->service->expressOptions()]);
    }

    public function store(WaybillAccountRequest $request)
    {
        $this->service->saveAccount(0, $request->validated());

        return ApiResponse::ok();
    }

    public function update(WaybillAccountRequest $request)
    {
        $this->service->saveAccount((int) $request->route('id'), $request->validated());

        return ApiResponse::ok();
    }

    public function destroy(Request $request)
    {
        $this->service->deleteAccount((int) $request->route('id'));

        return ApiResponse::ok();
    }

    public function options()
    {
        return ApiResponse::ok($this->service->shipOptions());
    }
}
