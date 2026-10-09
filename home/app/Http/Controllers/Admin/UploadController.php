<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\UploadImageRequest;
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
}
