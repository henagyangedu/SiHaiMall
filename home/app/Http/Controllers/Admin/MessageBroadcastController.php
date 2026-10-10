<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\MessageBroadcastSaveRequest;
use App\Services\Admin\MessageBroadcastService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 群发消息（站内信） */
class MessageBroadcastController extends BaseAdminController
{
    public function __construct(private MessageBroadcastService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['status', 'size'])));
    }

    public function store(MessageBroadcastSaveRequest $request)
    {
        return ApiResponse::ok(['id' => $this->service->create($request->validated(), (int) $this->admin($request)->id)->id]);
    }

    /** 立即发送（不等定时任务） */
    public function send(Request $request)
    {
        return ApiResponse::ok($this->service->sendNow((int) $request->route('id')));
    }

    public function revoke(Request $request)
    {
        $this->service->revoke((int) $request->route('id'));

        return ApiResponse::ok();
    }
}
