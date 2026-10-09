<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TranslateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from'     => ['required', 'string', 'max:10'],
            'to'       => ['required', 'string', 'max:10'],
            'fields'   => ['required', 'array', 'min:1', 'max:20'],
            'fields.*' => ['nullable', 'string', 'max:60000'],
        ];
    }

    public function attributes(): array
    {
        return ['from' => '源语言', 'to' => '目标语言', 'fields' => '待翻译内容', 'fields.*' => '待翻译内容'];
    }
}
