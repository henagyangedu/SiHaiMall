<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SmsCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile'     => ['required', 'string', 'regex:/^\d{6,15}$/'],
            'phone_area' => ['nullable', 'string', 'max:8'],
            'purpose'    => ['required', 'in:register,login,reset'],
        ];
    }

    public function attributes(): array
    {
        return ['mobile' => '手机号', 'phone_area' => '区号', 'purpose' => '验证码用途'];
    }
}
