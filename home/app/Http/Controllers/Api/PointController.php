<?php

namespace App\Http\Controllers\Api;

use App\Services\Api\PointService;
use App\Services\Common\PointRewardService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class PointController extends ApiController
{
    public function __construct(private PointService $service, private PointRewardService $rewards) {}

    /** 签到状态：有没有开签到、一次送多少、今天签过没有 */
    public function signInStatus(Request $request)
    {
        return ApiResponse::ok($this->rewards->signInStatus($this->memberId($request)));
    }

    /** 签到 */
    public function signIn(Request $request)
    {
        return ApiResponse::ok($this->rewards->signIn($this->memberId($request)));
    }

    /** 我的积分明细 */
    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->logs($this->memberId($request), (int) $request->query('page', 1), (int) $request->query('size', 20)));
    }
}
