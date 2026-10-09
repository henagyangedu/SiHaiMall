<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class LanguageSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'code'   => $create ? ['required', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'] : ['prohibited'],
            'name'   => ['required', 'string', 'max:50'],
            'status' => ['sometimes', 'in:0,1'],
            'sort'   => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function attributes(): array
    {
        return ['code' => '语言编码', 'name' => '语言名称', 'status' => '状态', 'sort' => '排序'];
    }
}
