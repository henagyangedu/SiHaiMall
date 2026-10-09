<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MessageTemplateTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:100'],
            'lang'  => ['nullable', 'string', 'max:10'],
        ];
    }

    public function attributes(): array
    {
        return ['email' => '收件邮箱', 'lang' => '语言'];
    }
}
