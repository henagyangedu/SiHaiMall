<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 必填项是否齐全取决于具体驱动，由 DriverConfigService 校验；这里只做结构校验 */
class DriverSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_enabled' => ['sometimes', 'in:0,1'],
            'is_default' => ['sometimes', 'in:0,1'],
            'sort'       => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'config'     => ['sometimes', 'array'],
        ];
    }

    public function attributes(): array
    {
        return ['is_enabled' => '启用状态', 'is_default' => '默认驱动', 'sort' => '排序', 'config' => '配置'];
    }
}
