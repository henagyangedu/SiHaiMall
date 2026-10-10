<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\CouponGrantRequest;
use App\Http\Requests\Admin\CouponSaveRequest;
use App\Http\Requests\Admin\StatusRequest;
use App\Services\Admin\CouponService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CouponController extends BaseAdminController
{
    public function __construct(private CouponService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['keyword', 'type', 'status', 'size'])));
    }

    public function store(CouponSaveRequest $request)
    {
        return ApiResponse::ok(['id' => $this->service->create($request->validated())->id]);
    }

    public function update(CouponSaveRequest $request)
    {
        $this->service->update((int) $request->route('id'), $request->validated());

        return ApiResponse::ok();
    }

    public function status(StatusRequest $request)
    {
        $this->service->setStatus((int) $request->route('id'), (int) $request->validated('status'));

        return ApiResponse::ok();
    }

    public function destroy(Request $request)
    {
        $this->service->delete((int) $request->route('id'));

        return ApiResponse::ok();
    }

    /** 发放给指定会员 */
    public function grant(CouponGrantRequest $request)
    {
        return ApiResponse::ok($this->service->grant((int) $request->route('id'), (array) $request->validated('members')));
    }

    /** 领取和使用记录 */
    public function records(Request $request)
    {
        return ApiResponse::ok($this->service->records((int) $request->route('id'), $request->only(['status', 'size'])));
    }
}
