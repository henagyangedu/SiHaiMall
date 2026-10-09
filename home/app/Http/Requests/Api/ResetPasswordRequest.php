<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** 短信找回密码 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile'   => ['required', 'string', 'regex:/^\d{6,15}$/'],
            'code'     => ['required', 'string', 'max:8'],
            'password' => ['required', 'string', 'min:6', 'max:64'],
        ];
    }

    public function attributes(): array
    {
        return ['mobile' => '手机号', 'code' => '验证码', 'password' => '新密码'];
    }
}
