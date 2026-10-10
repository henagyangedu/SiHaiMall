<?php

namespace App\Http\Controllers\Api;

use App\Services\Common\MemberMessageService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 站内信（消息中心） */
class MessageController extends ApiController
{
    public function __construct(private MemberMessageService $service) {}

    /** 我的消息；同时带回未读数 */
    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($this->memberId($request), (int) $request->query('page', 1), (int) $request->query('size', 10)));
    }

    /** 未读数（页头、个人中心的角标用） */
    public function unread(Request $request)
    {
        return ApiResponse::ok(['unread' => $this->service->unreadCount($this->memberId($request))]);
    }

    public function read(Request $request)
    {
        $this->service->read($this->memberId($request), (int) $request->route('id'));

        return ApiResponse::ok();
    }

    public function readAll(Request $request)
    {
        $this->service->readAll($this->memberId($request));

        return ApiResponse::ok();
    }

    public function destroy(Request $request)
    {
        $this->service->delete($this->memberId($request), (int) $request->route('id'));

        return ApiResponse::ok(null, '已删除');
    }
}
