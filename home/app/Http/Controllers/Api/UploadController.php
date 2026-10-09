<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\UploadImageRequest;
use App\Services\Common\UploadService;
use App\Support\ApiResponse;

class UploadController extends ApiController
{
    public function __construct(private UploadService $service) {}

    /** 买家上传图片：scene = refund 售后凭证 / avatar 头像。返回的 url 用于提交到对应业务接口 */
    public function image(UploadImageRequest $request)
    {
        return ApiResponse::ok($this->service->memberImage(
            $request->file('file'), (string) $request->validated('scene'), $this->memberId($request)
        ));
    }
}
