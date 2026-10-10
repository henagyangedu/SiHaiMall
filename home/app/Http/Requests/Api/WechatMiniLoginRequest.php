<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class WechatMiniLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'        => ['required', 'string', 'max:128'],
            'invite_code' => ['nullable', 'string', 'max:20'],
            // 同意用户协议和隐私政策：只在第一次登录（要新建会员）时看
            'agree'       => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['code' => '微信登录凭证', 'invite_code' => '邀请码'];
    }
}
