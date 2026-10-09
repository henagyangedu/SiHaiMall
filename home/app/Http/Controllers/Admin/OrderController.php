<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\OrderCancelRequest;
use App\Http\Requests\Admin\OrderPriceRequest;
use App\Http\Requests\Admin\OrderRemarkRequest;
use App\Services\Admin\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class OrderController extends BaseAdminController
{
    private const FILTERS = ['order_no', 'keyword', 'status', 'pay_status', 'pay_channel', 'date_from', 'date_to', 'size'];

    public function __construct(private OrderService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(self::FILTERS)));
    }

    public function counts()
    {
        return ApiResponse::ok($this->service->counts());
    }

    public function show(int $id)
    {
        return ApiResponse::ok($this->service->detail($id));
    }

    public function remark(OrderRemarkRequest $request, int $id)
    {
        $this->service->setRemark($id, (string) ($request->validated('admin_remark') ?? ''), $this->admin($request));

        return ApiResponse::ok();
    }

    public function price(OrderPriceRequest $request, int $id)
    {
        $this->service->changePrice($id, (string) $request->validated('pay_amount'), $this->admin($request));

        return ApiResponse::ok(null, '改价成功');
    }

    public function cancel(OrderCancelRequest $request, int $id)
    {
        $this->service->cancel($id, (string) ($request->validated('reason') ?? ''), $this->admin($request));

        return ApiResponse::ok(null, '订单已取消');
    }

    public function receive(Request $request, int $id)
    {
        $this->service->receive($id, $this->admin($request));

        return ApiResponse::ok(null, '已确认收货');
    }
}
