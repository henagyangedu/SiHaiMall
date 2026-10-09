<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BrandSaveRequest extends FormRequest
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
            'logo'   => ['nullable', 'string', 'max:255'],
            'sort'   => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status' => ['nullable', 'in:0,1'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => '品牌名称', 'name.*' => '品牌名称', 'logo' => '品牌 Logo', 'sort' => '排序', 'status' => '状态'];
    }
}
