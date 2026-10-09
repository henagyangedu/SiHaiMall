<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\MessageTemplateSaveRequest;
use App\Http\Requests\Admin\MessageTemplateTestRequest;
use App\Services\Admin\MessageTemplateService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MessageTemplateController extends BaseAdminController
{
    public function __construct(private MessageTemplateService $service) {}

    public function index()
    {
        return ApiResponse::ok($this->service->list());
    }

    public function save(MessageTemplateSaveRequest $request)
    {
        $this->service->save((string) $request->route('scene'), $request->validated());

        return ApiResponse::ok(null, '保存成功');
    }

    public function test(MessageTemplateTestRequest $request)
    {
        return ApiResponse::ok($this->service->test(
            (string) $request->route('scene'), (string) $request->validated('email'), (string) ($request->validated('lang') ?? '')
        ));
    }
}
