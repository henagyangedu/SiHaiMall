<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\InitService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    public function __construct(private InitService $service) {}

    /** C 端启动配置：语言、币种、站点信息（无需登录） */
    public function init(Request $request)
    {
        return ApiResponse::ok($this->service->init((string) $request->attributes->get('lang')));
    }
}
