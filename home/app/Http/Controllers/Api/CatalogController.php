<?php

namespace App\Http\Controllers\Api;

use App\Services\Api\CatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CatalogController extends ApiController
{
    public function __construct(private CatalogService $service) {}

    public function categories(Request $request)
    {
        return ApiResponse::ok($this->service->categories($this->lang($request)));
    }

    public function goods(Request $request)
    {
        return ApiResponse::ok($this->service->goodsList(
            $request->only(['category_id', 'keyword', 'sort', 'page', 'size']), $this->lang($request), $this->currency($request)
        ));
    }

    public function detail(Request $request)
    {
        return ApiResponse::ok($this->service->goodsDetail((int) $request->route('id'), $this->lang($request), $this->currency($request)));
    }
}
