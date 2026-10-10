<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\TaxRateRequest;
use App\Services\Admin\TaxRateService;
use App\Services\Common\TaxService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 税率设置：按收货地区的税率规则 */
class TaxRateController extends BaseAdminController
{
    public function __construct(private TaxRateService $service, private TaxService $tax) {}

    public function index()
    {
        // enabled：交易设置里的总开关，页面上用来提醒“规则配了但还没开启”
        return ApiResponse::ok(['list' => $this->service->list(), 'enabled' => $this->tax->enabled()]);
    }

    public function store(TaxRateRequest $request)
    {
        $this->service->save(0, $request->validated());

        return ApiResponse::ok();
    }

    public function update(TaxRateRequest $request)
    {
        $this->service->save((int) $request->route('id'), $request->validated());

        return ApiResponse::ok();
    }

    public function destroy(Request $request)
    {
        $this->service->delete((int) $request->route('id'));

        return ApiResponse::ok();
    }
}
