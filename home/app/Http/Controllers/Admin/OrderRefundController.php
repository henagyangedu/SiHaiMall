<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\RefundAuditRequest;
use App\Http\Requests\Admin\RefundPayRequest;
use App\Services\Admin\RefundService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class OrderRefundController extends BaseAdminController
{
    public function __construct(private RefundService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['keyword', 'status', 'type', 'size'])));
    }

    public function counts()
    {
        return ApiResponse::ok($this->service->counts());
    }

    public function show(Request $request)
    {
        return ApiResponse::ok($this->service->detail((int) $request->route('id')));
    }

    public function audit(RefundAuditRequest $request)
    {
        $v = $request->validated();
        $amount = isset($v['amount']) ? (string) $v['amount'] : null;
        $this->service->audit((int) $request->route('id'), (int) $v['pass'] === 1, $amount, (string) ($v['reject_reason'] ?? ''), $this->admin($request));

        return ApiResponse::ok();
    }

    public function confirm(Request $request)
    {
        $this->service->confirmReturn((int) $request->route('id'), $this->admin($request));

        return ApiResponse::ok();
    }

    public function pay(RefundPayRequest $request)
    {
        $r = $this->service->pay((int) $request->route('id'), (int) ($request->validated('offline') ?? 0) === 1, $this->admin($request));

        return ApiResponse::ok(['done' => $r['done']], $r['msg']);
    }
}
