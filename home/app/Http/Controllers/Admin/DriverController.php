<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\DriverSaveRequest;
use App\Services\Admin\DriverConfigService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * 接口配置：type（storage / translate 等）由路由 defaults 指定。
 * 注意：路由参数不要依赖方法形参顺序（defaults 排在路径参数之后，位置注入会把 type 与 driver 对调），
 * 统一用 $request->route('名称') 按名字取。
 */
class DriverController extends BaseAdminController
{
    public function __construct(private DriverConfigService $service) {}

    public function index(Request $request)
    {
        return ApiResponse::ok($this->service->list((string) $request->route('type')));
    }

    public function save(DriverSaveRequest $request)
    {
        $this->service->save((string) $request->route('type'), (string) $request->route('driver'), $request->validated());

        return ApiResponse::ok(null, '保存成功');
    }

    public function test(Request $request)
    {
        return ApiResponse::ok($this->service->test((string) $request->route('type'), (string) $request->route('driver')), '测试通过');
    }
}
