<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\InvoiceApplyRequest;
use App\Services\Api\OrderService;
use App\Services\Common\OrderInvoiceService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 订单发票：查看、申请 / 修改、撤销 */
class InvoiceController extends ApiController
{
    public function __construct(private OrderInvoiceService $service, private OrderService $orders) {}

    public function show(Request $request)
    {
        $order = $this->orders->own($this->memberId($request), (string) $request->route('orderNo'));

        return ApiResponse::ok($this->service->info($order, $this->lang($request)));
    }

    public function store(InvoiceApplyRequest $request)
    {
        $order = $this->orders->own($this->memberId($request), (string) $request->route('orderNo'));

        return ApiResponse::ok($this->service->apply($order, $request->validated()), '发票申请已提交');
    }

    public function destroy(Request $request)
    {
        $order = $this->orders->own($this->memberId($request), (string) $request->route('orderNo'));
        $this->service->cancel($order);

        return ApiResponse::ok(null, '已撤销发票申请');
    }
}
