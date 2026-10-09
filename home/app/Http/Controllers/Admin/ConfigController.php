<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ConfigSaveRequest;
use App\Services\Common\ConfigService;
use App\Support\ApiResponse;

/** 系统设置读写；group（base / trade）由路由的 defaults 指定，权限在路由上按分组区分 */
class ConfigController extends BaseAdminController
{
    public function __construct(private ConfigService $service) {}

    public function show(string $group)
    {
        return ApiResponse::ok($this->service->get($group));
    }

    public function save(ConfigSaveRequest $request, string $group)
    {
        $this->service->save($group, $request->validated());

        return ApiResponse::ok(null, '保存成功');
    }
}
