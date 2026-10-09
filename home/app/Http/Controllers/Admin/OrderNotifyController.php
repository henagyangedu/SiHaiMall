<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\PayNotifyService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class OrderNotifyController extends BaseAdminController
{
    public function __construct(private PayNotifyService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['channel', 'handle_status', 'trade_no', 'date_from', 'date_to', 'size'])));
    }

    public function show(int $id)
    {
        return ApiResponse::ok($this->service->show($id));
    }

    public function retry(int $id)
    {
        $this->service->retry($id);

        return ApiResponse::ok(null, '已重新处理');
    }
}
