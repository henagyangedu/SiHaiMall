<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\CancelAccountRequest;
use App\Services\Api\MemberPrivacyService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 买家的隐私权利：注销账号、导出自己的数据 */
class PrivacyController extends ApiController
{
    public function __construct(private MemberPrivacyService $service) {}

    /** 注销前的检查：能不能注销、不能的原因、会作废多少积分和优惠券 */
    public function cancelCheck(Request $request)
    {
        return ApiResponse::ok($this->service->cancelCheck($this->member($request)));
    }

    public function cancel(CancelAccountRequest $request)
    {
        $this->service->cancel($this->member($request), (string) ($request->validated('password') ?? ''));

        return ApiResponse::ok(null, '账号已注销');
    }

    public function export(Request $request)
    {
        return ApiResponse::ok($this->service->export($this->member($request)));
    }
}
