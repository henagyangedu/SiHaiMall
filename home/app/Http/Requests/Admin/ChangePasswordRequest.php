<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'old_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    public function attributes(): array
    {
        return ['old_password' => '原密码', 'new_password' => '新密码'];
    }
}
