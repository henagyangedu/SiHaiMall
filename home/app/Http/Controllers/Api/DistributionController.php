<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\DistributionApplyRequest;
use App\Http\Requests\Api\DistributionWithdrawRequest;
use App\Services\Api\DistributionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class DistributionController extends ApiController
{
    public function __construct(private DistributionService $service) {}

    /** 分销中心概况：邀请码、分销员状态、佣金账户、团队人数 */
    public function summary(Request $request)
    {
        return ApiResponse::ok($this->service->summary($this->member($request)));
    }

    public function apply(DistributionApplyRequest $request)
    {
        $this->service->apply($this->memberId($request), (string) $request->validated('real_name'), (string) ($request->validated('remark') ?? ''));

        return ApiResponse::ok(null, '申请已提交，请等待审核');
    }

    public function commissions(Request $request)
    {
        return ApiResponse::ok($this->service->commissions($this->memberId($request), $request->only(['status', 'page', 'size'])));
    }

    public function team(Request $request)
    {
        return ApiResponse::ok($this->service->team($this->memberId($request), $request->only(['level', 'page', 'size'])));
    }

    public function withdraws(Request $request)
    {
        return ApiResponse::ok($this->service->withdraws($this->memberId($request), $request->only(['page', 'size'])));
    }

    public function withdrawApply(DistributionWithdrawRequest $request)
    {
        return ApiResponse::ok($this->service->withdrawApply($this->memberId($request), $request->validated()), '提现申请已提交');
    }
}
