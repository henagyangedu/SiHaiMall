<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** 绑定邮箱或手机号（只能绑一次，换绑不在这里） */
class BindContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'       => ['required', 'in:email,phone'],
            'email'      => ['required_if:type,email', 'nullable', 'string', 'email', 'max:100'],
            'mobile'     => ['required_if:type,phone', 'nullable', 'string', 'regex:/^\d{6,15}$/'],
            'phone_area' => ['nullable', 'string', 'max:8'],
            'code'       => ['nullable', 'string', 'max:8'],
        ];
    }

    public function attributes(): array
    {
        return ['type' => '绑定类型', 'email' => '邮箱', 'mobile' => '手机号', 'phone_area' => '区号', 'code' => '验证码'];
    }
}
