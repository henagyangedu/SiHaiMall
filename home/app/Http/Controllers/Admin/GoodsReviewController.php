<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\GoodsReviewActionRequest;
use App\Services\Admin\GoodsReviewService;
use App\Support\ApiResponse;
use App\Support\Exceptions\BusinessException;
use Illuminate\Http\Request;

class GoodsReviewController extends BaseAdminController
{
    public function __construct(private GoodsReviewService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['keyword', 'rating', 'status', 'replied', 'goods_id', 'size'])));
    }

    /** 隐藏 / 恢复显示 */
    public function status(GoodsReviewActionRequest $request)
    {
        if (!$request->has('status')) {
            throw new BusinessException('请选择显示状态');
        }
        $this->service->setStatus((int) $request->route('id'), (int) $request->validated('status'));

        return ApiResponse::ok();
    }

    /** 商家回复 */
    public function reply(GoodsReviewActionRequest $request)
    {
        $this->service->reply((int) $request->route('id'), (string) ($request->validated('reply') ?? ''), (bool) $request->validated('append'));

        return ApiResponse::ok();
    }
}
