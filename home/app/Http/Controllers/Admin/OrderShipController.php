<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\OrderShipRequest;
use App\Services\Admin\OrderService;
use App\Services\Admin\OrderShipService;
use App\Support\ApiResponse;
use App\Support\OrderStatus;
use Illuminate\Http\Request;

class OrderShipController extends BaseAdminController
{
    public function __construct(private OrderService $orders, private OrderShipService $shipper) {}

    /** 发货管理列表：只看待发货、待收货 */
    public function index(Request $request)
    {
        return ApiResponse::ok($this->orders->list(
            $request->only(['order_no', 'keyword', 'status', 'date_from', 'date_to', 'size']),
            [OrderStatus::TO_SHIP, OrderStatus::SHIPPED],
        ));
    }

    public function express()
    {
        return ApiResponse::ok($this->shipper->expressOptions());
    }

    public function ship(OrderShipRequest $request, int $id)
    {
        $this->shipper->ship($id, $request->validated(), $this->admin($request));

        return ApiResponse::ok(null, '发货成功');
    }

    public function editExpress(OrderShipRequest $request, int $shippingId)
    {
        $this->shipper->editExpress($shippingId, $request->validated(), $this->admin($request));

        return ApiResponse::ok(null, '已修改');
    }
}
