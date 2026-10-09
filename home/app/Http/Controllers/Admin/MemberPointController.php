<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\MemberPointService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MemberPointController extends BaseAdminController
{
    public function __construct(private MemberPointService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list($request->only(['keyword', 'member_id', 'type', 'date_from', 'date_to', 'size'])));
    }
}
