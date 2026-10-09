<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** 邮箱找回密码 */
class EmailResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email', 'max:100'],
            'code'     => ['required', 'string', 'max:8'],
            'password' => ['required', 'string', 'min:6', 'max:64'],
        ];
    }

    public function attributes(): array
    {
        return ['email' => '邮箱', 'code' => '验证码', 'password' => '新密码'];
    }
}
