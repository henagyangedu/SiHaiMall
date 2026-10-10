<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\InvoiceIssueRequest;
use App\Http\Requests\Admin\InvoiceReasonRequest;
use App\Services\Admin\OrderInvoiceService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 发票管理 */
class OrderInvoiceController extends BaseAdminController
{
    public function __construct(private OrderInvoiceService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['status', 'keyword', 'size'])));
    }

    /** 开票：登记发票号码和文件 */
    public function issue(InvoiceIssueRequest $request)
    {
        $this->service->issue((int) $request->route('id'), $request->validated(), $this->admin($request));

        return ApiResponse::ok();
    }

    public function reject(InvoiceReasonRequest $request)
    {
        $this->service->reject((int) $request->route('id'), (string) $request->validated('reason'), $this->admin($request));

        return ApiResponse::ok();
    }

    public function void(InvoiceReasonRequest $request)
    {
        $this->service->void((int) $request->route('id'), (string) $request->validated('reason'), $this->admin($request));

        return ApiResponse::ok();
    }
}
