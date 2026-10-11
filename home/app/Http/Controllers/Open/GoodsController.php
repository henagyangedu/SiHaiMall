<?php

namespace App\Http\Controllers\Open;

use App\Http\Controllers\Controller;
use App\Http\Requests\Open\ListRequest;
use App\Http\Requests\Open\StockRequest;
use App\Services\Open\OpenGoodsService;
use App\Support\ApiResponse;

/** 开放接口：规格和库存 */
class GoodsController extends Controller
{
    public function __construct(private OpenGoodsService $service) {}

    public function skus(ListRequest $request)
    {
        return ApiResponse::ok($this->service->list($request->validated()));
    }

    public function stock(StockRequest $request)
    {
        // 校验通过后按请求里原来的顺序取（validated() 会把带 sku_id 的排到前面），返回的结果要和对方提交的一一对应
        $items = array_map(
            fn ($item) => array_intersect_key((array) $item, array_flip(['sku_id', 'sku_sn', 'mode', 'qty', 'remark'])),
            array_values((array) $request->input('items')),
        );

        return ApiResponse::ok(['results' => $this->service->updateStock($items, $request->attributes->get('open_app'))]);
    }
}
