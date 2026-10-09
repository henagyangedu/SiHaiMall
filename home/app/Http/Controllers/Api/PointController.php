<?php

namespace App\Http\Controllers\Api;

use App\Services\Api\PointService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PointController extends ApiController
{
    public function __construct(private PointService $service) {}

    /** 我的积分明细 */
    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->logs($this->memberId($request), (int) $request->query('page', 1), (int) $request->query('size', 20)));
    }
}
