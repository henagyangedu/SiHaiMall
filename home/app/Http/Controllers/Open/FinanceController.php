<?php

namespace App\Http\Controllers\Open;

use App\Http\Controllers\Controller;
use App\Http\Requests\Open\ListRequest;
use App\Services\Open\OpenFinanceService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 开放接口：支付流水、售后单（只读） */
class FinanceController extends Controller
{
    public function __construct(private OpenFinanceService $service) {}

    public function payTrades(ListRequest $request)
    {
        return ApiResponse::ok($this->service->payTrades($request->validated()));
    }

    public function refunds(ListRequest $request)
    {
        return ApiResponse::ok($this->service->refunds($request->validated()));
    }

    public function refund(Request $request)
    {
        return ApiResponse::ok($this->service->refund((string) $request->route('refund_no')));
    }
}
