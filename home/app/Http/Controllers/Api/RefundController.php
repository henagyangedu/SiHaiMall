<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\RefundApplyRequest;
use App\Http\Requests\Api\RefundReturnRequest;
use App\Services\Api\RefundService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class RefundController extends ApiController
{
    public function __construct(private RefundService $service) {}

    /** 订单当前可申请售后的商品、数量和可退金额 */
    public function refundable(Request $request)
    {
        return ApiResponse::ok($this->service->refundable($this->memberId($request), (string) $request->route('orderNo')));
    }

    public function apply(RefundApplyRequest $request)
    {
        return ApiResponse::ok($this->service->apply($this->memberId($request), (string) $request->route('orderNo'), $request->validated()));
    }

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($this->memberId($request), (int) $request->query('page', 1), (int) $request->query('size', 10)));
    }

    public function show(Request $request)
    {
        return ApiResponse::ok($this->service->detail($this->memberId($request), (string) $request->route('refundNo')));
    }

    public function cancel(Request $request)
    {
        $this->service->cancel($this->memberId($request), (string) $request->route('refundNo'));

        return ApiResponse::ok();
    }

    public function submitReturn(RefundReturnRequest $request)
    {
        $this->service->submitReturn(
            $this->memberId($request), (string) $request->route('refundNo'),
            (string) $request->validated('express'), (string) $request->validated('express_no')
        );

        return ApiResponse::ok();
    }
}
