<?php

namespace App\Http\Controllers\Admin;

use App\Services\Admin\LogService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class LogController extends BaseAdminController
{
    public function __construct(private LogService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list(
            $request->only(['admin_id', 'module', 'status', 'keyword', 'date_from', 'date_to', 'size'])
        ));
    }
}
