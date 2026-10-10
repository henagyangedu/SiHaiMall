<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CancelAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['password' => ['nullable', 'string', 'max:64']];
    }

    public function attributes(): array
    {
        return ['password' => '密码'];
    }
}
