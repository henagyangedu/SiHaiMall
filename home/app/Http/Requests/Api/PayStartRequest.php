<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PayStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel'    => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/'],
            'return_url' => ['nullable', 'string', 'max:500'],
            // 小程序里用微信支付时带上 wx.login 拿到的 code，后端用它换 openid
            'wx_code'    => ['nullable', 'string', 'max:128'],
        ];
    }

    public function attributes(): array
    {
        return [
            'channel' => '支付方式',
        ];
    }
}
