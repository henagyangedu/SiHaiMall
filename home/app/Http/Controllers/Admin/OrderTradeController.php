<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\PayTradeService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class OrderTradeController extends BaseAdminController
{
    public function __construct(private PayTradeService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['keyword', 'channel', 'status', 'trade_type', 'flagged', 'date_from', 'date_to', 'size'])));
    }
}
