<?php

namespace App\Http\Controllers\Open;

use App\Http\Controllers\Controller;
use App\Http\Requests\Open\ListRequest;
use App\Http\Requests\Open\ShipRequest;
use App\Services\Open\OpenOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 开放接口：订单、发货 */
class OrderController extends Controller
{
    public function __construct(private OpenOrderService $service) {}

    public function index(ListRequest $request)
    {
        return ApiResponse::ok($this->service->list($request->validated()));
    }

    public function show(Request $request)
    {
        return ApiResponse::ok($this->service->detail((string) $request->route('order_no')));
    }

    public function ship(ShipRequest $request)
    {
        return ApiResponse::ok($this->service->ship((string) $request->route('order_no'), $request->validated(), $request->attributes->get('open_app')));
    }

    public function editShipment(ShipRequest $request)
    {
        return ApiResponse::ok($this->service->editShipment((int) $request->route('id'), $request->validated(), $request->attributes->get('open_app')));
    }

    public function expressCompanies()
    {
        return ApiResponse::ok($this->service->expressCompanies());
    }
}
