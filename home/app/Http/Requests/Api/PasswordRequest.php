<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 还没有设置过密码的会员（如微信一键登录注册的）不用填；有密码时由服务层要求必填
            'old_password' => ['nullable', 'string', 'max:64'],
            'password'     => ['required', 'string', 'min:6', 'max:64'],
        ];
    }

    public function attributes(): array
    {
        return [
            'old_password' => '原密码', 'password' => '新密码',
        ];
    }
}
