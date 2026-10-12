<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\OpenAppRequest;
use App\Services\Admin\OpenAppAdminService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 设置 → 开放接口：给外部系统（ERP、财务、物流）发密钥 */
class OpenAppController extends BaseAdminController
{
    public function __construct(private OpenAppAdminService $service) {}

    public function index()
    {
        return ApiResponse::ok(['list' => $this->service->list(), 'scopes' => $this->service->scopeOptions(), 'events' => $this->service->eventOptions()]);
    }

    public function store(OpenAppRequest $request)
    {
        return ApiResponse::ok($this->service->create($request->validated()));
    }

    public function update(OpenAppRequest $request)
    {
        return ApiResponse::ok($this->service->update((int) $request->route('id'), $request->validated()));
    }

    public function resetWebhookSecret(Request $request)
    {
        return ApiResponse::ok($this->service->resetWebhookSecret((int) $request->route('id')));
    }

    public function webhooks(Request $request)
    {
        return ApiResponse::ok($this->service->webhooks((int) $request->route('id'), (int) $request->query('page', 1), (int) $request->query('size', 20)));
    }

    public function resendWebhook(Request $request)
    {
        return ApiResponse::ok($this->service->resend((int) $request->route('id'), (int) $request->route('webhook_id')));
    }

    public function testWebhook(Request $request)
    {
        return ApiResponse::ok($this->service->testWebhook((int) $request->route('id')));
    }

    public function resetKey(Request $request)
    {
        return ApiResponse::ok($this->service->resetKey((int) $request->route('id')));
    }

    public function destroy(Request $request)
    {
        $this->service->delete((int) $request->route('id'));

        return ApiResponse::ok();
    }

    public function logs(Request $request)
    {
        return ApiResponse::ok($this->service->logs((int) $request->route('id'), (int) $request->query('page', 1), (int) $request->query('size', 20)));
    }
}
