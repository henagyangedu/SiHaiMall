<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\RefundApplyRequest;
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

    /** 订单当前可以发起售后的商品（商家代买家发起用，路由参数是订单 ID） */
    public function refundable(Request $request)
    {
        return ApiResponse::ok($this->service->refundable((int) $request->route('id')));
    }

    /** 商家代买家发起售后（路由参数是订单 ID） */
    public function apply(RefundApplyRequest $request)
    {
        return ApiResponse::ok($this->service->apply((int) $request->route('id'), $request->validated(), $this->admin($request)));
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
