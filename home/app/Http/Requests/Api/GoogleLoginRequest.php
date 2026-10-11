<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class GoogleLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Google 交给页面的身份凭证（ID token），一般一千多个字符
            'credential'  => ['required', 'string', 'max:4096'],
            'invite_code' => ['nullable', 'string', 'max:20'],
            // 同意用户协议和隐私政策：只在第一次登录（要新建会员）时看
            'agree'       => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['credential' => 'Google 登录凭证', 'invite_code' => '邀请码'];
    }
}
