<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account'    => ['required', 'string', 'min:3', 'max:100', 'regex:/^[A-Za-z0-9_.@+\-]+$/'],
            'password'   => ['required', 'string', 'min:6', 'max:64'],
            'nickname'   => ['nullable', 'string', 'max:60'],
            'phone_area' => ['nullable', 'string', 'max:8'],
            'code'       => ['nullable', 'string', 'max:8'],
            'invite_code' => ['nullable', 'string', 'max:20'],
            'lang'       => ['nullable', 'string', 'max:10'],
            'currency'   => ['nullable', 'string', 'max:10'],
        ];
    }

    public function attributes(): array
    {
        return [
            'account' => '账号', 'password' => '密码', 'nickname' => '昵称', 'code' => '验证码', 'invite_code' => '邀请码',
        ];
    }
}
