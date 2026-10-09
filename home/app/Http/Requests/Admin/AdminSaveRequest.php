<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** 新增（POST）与编辑（PUT）共用；编辑时账号不可改、密码可不填 */
class AdminSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'username' => $create ? ['required', 'string', 'alpha_dash:ascii', 'between:3,50'] : ['prohibited'],
            'password' => $create
                ? ['required', 'string', Password::min(8)->letters()->numbers()]
                : ['nullable', 'string', Password::min(8)->letters()->numbers()],
            'nickname' => ['nullable', 'string', 'max:50'],
            'phone'    => ['nullable', 'string', 'max:30'],
            'email'    => ['nullable', 'email', 'max:100'],
            'avatar'   => ['nullable', 'string', 'max:255'],
            'role_id'  => [$create ? 'required' : 'sometimes', 'integer', 'min:0'],
            'status'   => ['sometimes', 'in:0,1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'username' => '账号', 'password' => '密码', 'nickname' => '昵称',
            'phone' => '手机号', 'email' => '邮箱', 'role_id' => '角色', 'status' => '状态',
        ];
    }
}
