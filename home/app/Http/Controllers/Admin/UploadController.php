<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UploadImageRequest;
use App\Http\Requests\Admin\UploadInvoiceRequest;
use App\Services\Common\UploadService;
use App\Support\ApiResponse;

class UploadController extends BaseAdminController
{
    public function __construct(private UploadService $service) {}

    public function image(UploadImageRequest $request)
    {
        $data = $this->service->image(
            $request->file('file'),
            (string) ($request->validated('scene') ?? 'other'),
            (int) $this->admin($request)->id,
        );

        return ApiResponse::ok($data);
    }

    /** 发票文件：PDF 或图片 */
    public function invoice(UploadInvoiceRequest $request)
    {
        return ApiResponse::ok($this->service->invoiceFile($request->file('file'), (int) $this->admin($request)->id));
    }
}
