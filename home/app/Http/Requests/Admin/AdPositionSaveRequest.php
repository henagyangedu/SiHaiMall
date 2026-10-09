<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdPositionSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'   => ['required', 'string', 'max:50', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/'],
            'name'   => ['required', 'string', 'max:100'],
            'remark' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:0,1'],
        ];
    }

    public function messages(): array
    {
        return ['code.regex' => '广告位编码只能用字母、数字和下划线，且以字母开头'];
    }

    public function attributes(): array
    {
        return ['code' => '广告位编码', 'name' => '广告位名称', 'remark' => '说明', 'status' => '状态'];
    }
}
