<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CategorySaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pid'    => ['nullable', 'integer', 'min:0'],
            'name'   => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:100'],
            'image'  => ['nullable', 'string', 'max:255'],
            'sort'   => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => ['nullable', 'in:0,1'],
        ];
    }

    public function attributes(): array
    {
        return ['pid' => '上级分类', 'name' => '分类名称', 'name.*' => '分类名称', 'image' => '分类图片', 'sort' => '排序', 'status' => '状态'];
    }
}
