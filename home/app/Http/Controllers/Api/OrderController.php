<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\CheckoutRequest;
use App\Http\Requests\Api\OrderCancelRequest;
use App\Http\Requests\Api\OrderCreateRequest;
use App\Services\Api\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class OrderController extends ApiController
{
    public function __construct(private OrderService $service) {}

    public function checkout(CheckoutRequest $request)
    {
        return ApiResponse::ok($this->service->checkout($this->member($request), $request->validated(), $this->lang($request), $this->currency($request)));
    }

    public function create(OrderCreateRequest $request)
    {
        return ApiResponse::ok($this->service->create(
            $this->member($request), $request->validated(), $this->lang($request), $this->currency($request), $this->source($request)
        ));
    }

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list(
            $this->memberId($request), (string) $request->query('tab', 'all'), (int) $request->query('page', 1), (int) $request->query('size', 10)
        ));
    }

    public function show(Request $request)
    {
        return ApiResponse::ok($this->service->detail($this->memberId($request), (string) $request->route('orderNo')));
    }

    public function cancel(OrderCancelRequest $request)
    {
        $this->service->cancel($this->memberId($request), (string) $request->route('orderNo'), (string) $request->validated('reason', ''));

        return ApiResponse::ok();
    }

    /** 订单每个包裹的物流轨迹 */
    public function tracks(Request $request)
    {
        return ApiResponse::ok($this->service->tracks($this->memberId($request), (string) $request->route('orderNo'), $this->lang($request)));
    }

    public function receive(Request $request)
    {
        $this->service->receive($this->memberId($request), (string) $request->route('orderNo'));

        return ApiResponse::ok();
    }
}
