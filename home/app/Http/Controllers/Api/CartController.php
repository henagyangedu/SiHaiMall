<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\CartAddRequest;
use App\Http\Requests\Api\CartQtyRequest;
use App\Http\Requests\Api\CartRemoveRequest;
use App\Services\Api\CartService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CartController extends ApiController
{
    public function __construct(private CartService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($this->memberId($request), $this->lang($request), $this->currency($request)));
    }

    public function add(CartAddRequest $request)
    {
        $this->service->add($this->memberId($request), (int) $request->validated('sku_id'), (int) $request->validated('qty'));

        return ApiResponse::ok();
    }

    public function qty(CartQtyRequest $request)
    {
        $this->service->setQty($this->memberId($request), (int) $request->route('id'), (int) $request->validated('qty'));

        return ApiResponse::ok();
    }

    public function remove(CartRemoveRequest $request)
    {
        $this->service->remove($this->memberId($request), array_map('intval', $request->validated('ids')));

        return ApiResponse::ok();
    }

    public function clearInvalid(Request $request)
    {
        return ApiResponse::ok(['removed' => $this->service->clearInvalid($this->memberId($request))]);
    }
}
