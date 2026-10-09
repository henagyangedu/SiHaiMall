<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['password' => ['required', 'string', Password::min(8)->letters()->numbers()]];
    }

    public function attributes(): array
    {
        return ['password' => '新密码'];
    }
}
