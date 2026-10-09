<?php

namespace App\Http\Requests\Admin;

use App\Services\Common\ConfigService;
use Illuminate\Foundation\Http\FormRequest;

/** 系统设置保存：校验规则由 ConfigService 的配置定义生成，group 来自路由默认参数 */
class ConfigSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return app(ConfigService::class)->rules((string) $this->route('group'));
    }

    public function attributes(): array
    {
        return app(ConfigService::class)->attributes((string) $this->route('group'));
    }
}
