<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\CurrencyRateRequest;
use App\Http\Requests\Admin\CurrencySaveRequest;
use App\Services\Admin\CurrencyService;
use App\Support\ApiResponse;

class CurrencyController extends BaseAdminController
{
    public function __construct(private CurrencyService $service) {}

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
