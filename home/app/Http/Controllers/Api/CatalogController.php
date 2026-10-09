<?php

namespace App\Http\Controllers\Api;

use App\Services\Api\CatalogService;
use App\Services\Common\GoodsReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CatalogController extends ApiController
{
    public function __construct(private CatalogService $service, private GoodsReviewService $reviews) {}

    public function categories(Request $request)
    {
        return ApiResponse::ok($this->service->categories($this->lang($request)));
    }

    public function goods(Request $request)
    {
        return ApiResponse::ok($this->service->goodsList(
            $request->only(['category_id', 'keyword', 'sort', 'page', 'size']), $this->lang($request), $this->currency($request), $this->region($request)
        ));
    }

    public function detail(Request $request)
    {
        $id = (int) $request->route('id');

        // review：评价数、好评率等概况；评价列表另见 goods/{id}/reviews
        return ApiResponse::ok($this->service->goodsDetail($id, $this->lang($request), $this->currency($request), $this->region($request)) + ['review' => $this->reviews->summary($id)]);
    }
}
