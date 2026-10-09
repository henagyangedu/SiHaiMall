<?php

namespace App\Services\Api;

use App\Services\Common\GoodsReviewService;

/** C 端评价入口：按订单号找订单并校验归属，再交给通用的评价服务 */
class ReviewService
{
    public function __construct(private OrderService $orders, private GoodsReviewService $reviews) {}

    public function create(int $memberId, string $orderNo, array $d): array
    {
        return $this->reviews->create($this->orders->own($memberId, $orderNo), $d);
    }

    public function byGoods(int $goodsId, string $level, int $page, int $size): array
    {
        return ['summary' => $this->reviews->summary($goodsId)] + $this->reviews->byGoods($goodsId, $level, $page, $size);
    }
}
