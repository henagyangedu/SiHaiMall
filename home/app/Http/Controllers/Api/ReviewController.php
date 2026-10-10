<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ReviewAppendRequest;
use App\Http\Requests\Api\ReviewCreateRequest;
use App\Services\Api\ReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ReviewController extends ApiController
{
    public function __construct(private ReviewService $service) {}

    /** 商品的评价概况和评价列表（无需登录）。level：all / good / mid / bad / image */
    public function index(Request $request)
    {
        $level = (string) $request->query('level', 'all');

        return ApiResponse::ok($this->service->byGoods(
            (int) $request->route('id'), in_array($level, ['good', 'mid', 'bad', 'image'], true) ? $level : 'all',
            (int) $request->query('page', 1), (int) $request->query('size', 10)
        ));
    }

    /** 评价订单里的一件商品 */
    public function store(ReviewCreateRequest $request)
    {
        return ApiResponse::ok($this->service->create($this->memberId($request), (string) $request->route('orderNo'), $request->validated()), '评价成功');
    }

    /** 追评：给评价过的商品再追加一次 */
    public function append(ReviewAppendRequest $request)
    {
        $this->service->append($this->memberId($request), (string) $request->route('orderNo'), $request->validated());

        return ApiResponse::ok(null, '追评成功');
    }
}
