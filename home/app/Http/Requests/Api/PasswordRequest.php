<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'old_password' => ['required', 'string', 'max:64'],
            'password'     => ['required', 'string', 'min:6', 'max:64'],
        ];
    }

    public function attributes(): array
    {
        return [
            'old_password' => '原密码', 'password' => '新密码',
        ];
    }
}
