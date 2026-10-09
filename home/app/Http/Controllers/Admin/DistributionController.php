<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\DistributionAuditRequest;
use App\Http\Requests\Admin\DistributionParentRequest;
use App\Http\Requests\Admin\DistributionWithdrawPaidRequest;
use App\Http\Requests\Admin\StatusRequest;
use App\Services\Admin\DistributionService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class DistributionController extends BaseAdminController
{
    public function __construct(private DistributionService $service) {}

    // ---- 分销员
    public function distributors(Request $request)
    {
        return ApiResponse::ok($this->service->distributors($request->only(['keyword', 'status', 'size'])));
    }

    public function audit(DistributionAuditRequest $request)
    {
        $v = $request->validated();
        $this->service->audit((int) $request->route('id'), (int) $v['pass'] === 1, (string) ($v['reject_reason'] ?? ''));

        return ApiResponse::ok();
    }

    public function status(StatusRequest $request)
    {
        $this->service->setEnabled((int) $request->route('id'), (int) $request->validated('status') === 1);

        return ApiResponse::ok();
    }

    /** 后台直接指定某个会员为分销员 */
    public function appoint(Request $request)
    {
        $this->service->appoint((int) $request->route('id'));

        return ApiResponse::ok();
    }

    public function relation(Request $request)
    {
        return ApiResponse::ok($this->service->relation((int) $request->route('id')));
    }

    public function setParent(DistributionParentRequest $request)
    {
        $this->service->setParent((int) $request->route('id'), (int) $request->validated('parent_id'));

        return ApiResponse::ok();
    }

    // ---- 佣金明细
    public function commissions(Request $request)
    {
        return ApiResponse::ok($this->service->commissions($request->only(['keyword', 'status', 'member_id', 'size'])));
    }

    // ---- 提现
    public function withdraws(Request $request)
    {
        return ApiResponse::ok($this->service->withdraws($request->only(['keyword', 'status', 'size'])));
    }

    public function withdrawAudit(DistributionAuditRequest $request)
    {
        $v = $request->validated();
        $this->service->withdrawAudit((int) $request->route('id'), (int) $v['pass'] === 1, (string) ($v['reject_reason'] ?? ''), $this->admin($request));

        return ApiResponse::ok();
    }

    public function withdrawPaid(DistributionWithdrawPaidRequest $request)
    {
        $this->service->withdrawPaid((int) $request->route('id'), (string) ($request->validated('pay_remark') ?? ''), $this->admin($request));

        return ApiResponse::ok();
    }
}
