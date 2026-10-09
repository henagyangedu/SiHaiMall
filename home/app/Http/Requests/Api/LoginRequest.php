<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account'  => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:64'],
        ];
    }

    public function attributes(): array
    {
        return [
            'account' => '账号', 'password' => '密码',
        ];
    }
}
