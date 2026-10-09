<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MenuSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = (int) $this->input('type');

        return [
            'pid'       => ['nullable', 'integer', 'min:0'],
            'type'      => ['required', 'integer', 'in:1,2,3'],
            'title_key' => ['required', 'string', 'max:100'],
            'icon'      => ['nullable', 'string', 'max:60'],
            'path'      => [Rule::requiredIf($type === 2), 'nullable', 'string', 'max:150'],
            'component' => ['nullable', 'string', 'max:150'],
            'perm'      => [Rule::requiredIf(in_array($type, [2, 3], true)), 'nullable', 'string', 'max:100'],
            'api_rule'  => ['nullable', 'string', 'max:255'],
            'sort'      => ['nullable', 'integer'],
            'visible'   => ['nullable', 'in:0,1'],
            'status'    => ['nullable', 'in:0,1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'pid' => '上级菜单', 'type' => '类型', 'title_key' => '菜单名称', 'path' => '路由',
            'perm' => '权限标识', 'sort' => '排序',
        ];
    }
}
