<?php

namespace App\Http\Controllers\Api;

use App\Services\Api\FavoriteService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class FavoriteController extends ApiController
{
    public function __construct(private FavoriteService $service) {}

    /** 我的收藏 */
    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list(
            $this->memberId($request), (int) $request->query('page', 1), (int) $request->query('size', 10),
            $this->lang($request), $this->currency($request), $this->region($request)
        ));
    }

    /** 收藏一个商品 */
    public function store(Request $request)
    {
        $this->service->add($this->memberId($request), (int) $request->route('goodsId'));

        return ApiResponse::ok(null, '已收藏');
    }

    /** 取消收藏 */
    public function destroy(Request $request)
    {
        $this->service->remove($this->memberId($request), (int) $request->route('goodsId'));

        return ApiResponse::ok(null, '已取消收藏');
    }
}
