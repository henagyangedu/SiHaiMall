<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class EmailCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'   => ['required', 'string', 'email', 'max:100'],
            'purpose' => ['required', 'in:register,reset'],
        ];
    }

    public function attributes(): array
    {
        return ['email' => '邮箱', 'purpose' => '验证码用途'];
    }
}
