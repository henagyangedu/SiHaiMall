<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RoleSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:50'],
            'remark'     => ['nullable', 'string', 'max:255'],
            'status'     => ['sometimes', 'in:0,1'],
            'menu_ids'   => ['sometimes', 'array'],
            'menu_ids.*' => ['integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => '角色名称', 'remark' => '备注', 'status' => '状态', 'menu_ids' => '菜单权限'];
    }
}
