<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\StatsService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 数据中心：只读统计接口。days 只接受 7 / 30 / 90，其他值按 30 处理 */
class StatsController extends BaseAdminController
{
    public function __construct(private StatsService $service) {}

    public function overview()
    {
        return ApiResponse::ok($this->service->overview());
    }

    public function trend(Request $request)
    {
        return ApiResponse::ok($this->service->trend((int) $request->query('days', 30)));
    }

    public function goodsRank(Request $request)
    {
        return ApiResponse::ok($this->service->goodsRank((int) $request->query('days', 30)));
    }

    public function breakdown(Request $request)
    {
        return ApiResponse::ok($this->service->breakdown((int) $request->query('days', 30)));
    }
}
