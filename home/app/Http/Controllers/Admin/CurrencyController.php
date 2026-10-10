<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\CurrencyRateConfigRequest;
use App\Http\Requests\Admin\CurrencyRateModeRequest;
use App\Http\Requests\Admin\CurrencyRateRequest;
use App\Http\Requests\Admin\CurrencySaveRequest;
use App\Services\Admin\CurrencyService;
use App\Services\Common\ExchangeRateService;
use App\Support\ApiResponse;

class CurrencyController extends BaseAdminController
{
    public function __construct(private CurrencyService $service, private ExchangeRateService $rates) {}

    public function index()
    {
        return ApiResponse::ok($this->service->list());
    }

    public function store(CurrencySaveRequest $request)
    {
        $cur = $this->service->create($request->validated());

        return ApiResponse::ok(['id' => $cur->id]);
    }

    public function update(CurrencySaveRequest $request, int $id)
    {
        $this->service->update($id, $request->validated());

        return ApiResponse::ok();
    }

    public function rate(CurrencyRateRequest $request, int $id)
    {
        $this->service->updateRate($id, (string) $request->validated('rate'));

        return ApiResponse::ok();
    }

    public function rateMode(CurrencyRateModeRequest $request)
    {
        $this->service->setRateMode((int) $request->route('id'), (int) $request->validated('mode'));

        return ApiResponse::ok();
    }

    /** 汇率自动更新的设置和最近一次更新的结果 */
    public function rateConfig()
    {
        return ApiResponse::ok($this->rates->settings());
    }

    public function saveRateConfig(CurrencyRateConfigRequest $request)
    {
        $this->rates->saveSettings($request->validated());

        return ApiResponse::ok();
    }

    /** 立即更新一次 */
    public function rateSync()
    {
        return ApiResponse::ok($this->rates->sync(true));
    }

    public function setBase(int $id)
    {
        $this->service->setBase($id);

        return ApiResponse::ok(null, '基准币种已更换，其它币种汇率已按新基准重新换算');
    }

    public function destroy(int $id)
    {
        $this->service->delete($id);

        return ApiResponse::ok();
    }
}
