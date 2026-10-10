<?php

namespace App\Http\Controllers\Api;

use App\Services\Common\StockSubscribeService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class StockSubscribeController extends ApiController
{
    public function __construct(private StockSubscribeService $service) {}

    /** 我的到货提醒 */
    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list(
            $this->memberId($request), (int) $request->query('page', 1), (int) $request->query('size', 10),
            $this->lang($request), $this->currency($request), $this->region($request)
        ));
    }

    /** 订阅某个规格的到货提醒 */
    public function store(Request $request)
    {
        $this->service->subscribe($this->memberId($request), (int) $request->route('skuId'));

        return ApiResponse::ok(null, '到货后会通知你');
    }

    /** 取消到货提醒 */
    public function destroy(Request $request)
    {
        $this->service->unsubscribe($this->memberId($request), (int) $request->route('skuId'));

        return ApiResponse::ok(null, '已取消到货提醒');
    }
}
