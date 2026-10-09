<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\OrderCancelRequest;
use App\Http\Requests\Admin\OrderPriceRequest;
use App\Http\Requests\Admin\OrderRemarkRequest;
use App\Services\Admin\OrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class OrderController extends BaseAdminController
{
    private const FILTERS = ['order_no', 'keyword', 'status', 'pay_status', 'pay_channel', 'date_from', 'date_to', 'size'];

    public function __construct(private OrderService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(self::FILTERS)));
    }

    /** 导出 CSV（与列表相同的筛选条件，流式输出） */
    public function export(Request $request)
    {
        $filters = $request->only(self::FILTERS);

        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, OrderService::EXPORT_HEAD, ',', '"', '');
            foreach ($this->service->exportRows($filters) as $row) {
                // 以 = + - @ 开头的文本前面加单引号，防止表格软件把它当公式执行
                fputcsv($out, array_map(fn ($v) => is_string($v) && $v !== '' && str_contains('=+-@', $v[0]) ? "'" . $v : $v, $row), ',', '"', '');
            }
            fclose($out);
        }, 'orders_' . date('Ymd_His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function counts()
    {
        return ApiResponse::ok($this->service->counts());
    }

    public function show(int $id)
    {
        return ApiResponse::ok($this->service->detail($id));
    }

    public function remark(OrderRemarkRequest $request, int $id)
    {
        $this->service->setRemark($id, (string) ($request->validated('admin_remark') ?? ''), $this->admin($request));

        return ApiResponse::ok();
    }

    public function price(OrderPriceRequest $request, int $id)
    {
        $this->service->changePrice($id, (string) $request->validated('pay_amount'), $this->admin($request));

        return ApiResponse::ok(null, '改价成功');
    }

    public function cancel(OrderCancelRequest $request, int $id)
    {
        $this->service->cancel($id, (string) ($request->validated('reason') ?? ''), $this->admin($request));

        return ApiResponse::ok(null, '订单已取消');
    }

    public function receive(Request $request, int $id)
    {
        $this->service->receive($id, $this->admin($request));

        return ApiResponse::ok(null, '已确认收货');
    }
}
