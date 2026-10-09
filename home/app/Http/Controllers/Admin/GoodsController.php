<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\GoodsSaveRequest;
use App\Http\Requests\Admin\StatusRequest;
use App\Http\Requests\Admin\StockAdjustRequest;
use App\Services\Admin\GoodsService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class GoodsController extends BaseAdminController
{
    public function __construct(private GoodsService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list(
            $request->only(['keyword', 'category_id', 'brand_id', 'is_on_sale', 'low_stock', 'size'])
        ));
    }

    public function show(int $id)
    {
        return ApiResponse::ok($this->service->detail($id));
    }

    public function store(GoodsSaveRequest $request)
    {
        $goods = $this->service->create($request->validated(), $this->admin($request));

        return ApiResponse::ok(['id' => $goods->id]);
    }

    public function update(GoodsSaveRequest $request, int $id)
    {
        $this->service->update($id, $request->validated(), $this->admin($request));

        return ApiResponse::ok();
    }

    public function sale(StatusRequest $request, int $id)
    {
        $this->service->setSale($id, (int) $request->validated('status'));

        return ApiResponse::ok();
    }

    public function stock(StockAdjustRequest $request, int $id)
    {
        $v = $request->validated();
        $after = $this->service->adjustStock(
            $id,
            isset($v['sku_id']) ? (int) $v['sku_id'] : null,
            (int) $v['change'],
            (string) ($v['remark'] ?? ''),
            $this->admin($request),
        );

        return ApiResponse::ok(['stock' => $after], '库存已调整');
    }

    public function destroy(int $id)
    {
        $this->service->delete($id);

        return ApiResponse::ok();
    }
}
