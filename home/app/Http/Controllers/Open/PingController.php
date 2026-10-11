<?php

namespace App\Http\Controllers\Open;

use App\Http\Controllers\Controller;
use App\Models\OpenApp;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** 开放接口：连通性测试。对接时先调它，确认密钥可用、看到自己有哪些权限 */
class PingController extends Controller
{
    public function __invoke(Request $request)
    {
        /** @var OpenApp $app */
        $app = $request->attributes->get('open_app');

        return ApiResponse::ok([
            'app' => (string) $app->name,
            'scopes' => array_values((array) $app->scopes),
            // 接口里所有的时间都是这个时区的
            'timezone' => (string) config('app.timezone'),
            'time' => now()->format('Y-m-d H:i:s'),
        ]);
    }
}
