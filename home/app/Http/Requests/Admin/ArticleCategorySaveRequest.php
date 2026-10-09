<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ArticleCategorySaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'   => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:100'],
            'sort'   => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => ['nullable', 'in:0,1'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => '分类名称', 'name.*' => '分类名称', 'sort' => '排序', 'status' => '状态'];
    }
}
