<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GlossarySaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'term'        => ['required', 'string', 'max:200'],
            'lang'        => ['required', 'string', 'max:10'],
            'translation' => ['required', 'string', 'max:200'],
        ];
    }

    public function attributes(): array
    {
        return ['term' => '原文术语', 'lang' => '目标语言', 'translation' => '固定译法'];
    }
}
