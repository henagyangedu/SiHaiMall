<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\StockLogService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class StockLogController extends BaseAdminController
{
    public function __construct(private StockLogService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list(
            $request->only(['keyword', 'goods_id', 'type', 'date_from', 'date_to', 'size'])
        ));
    }
}
