<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 新增（POST）与编辑（PUT）共用；编辑时代码和汇率不可改（汇率走单独接口） */
class CurrencySaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'code'     => $create ? ['required', 'string', 'regex:/^[A-Z]{3}$/'] : ['prohibited'],
            'name'     => ['required', 'string', 'max:50'],
            'symbol'   => ['nullable', 'string', 'max:10'],
            'rate'     => $create ? ['required', 'numeric', 'gt:0', 'lt:1000000000'] : ['prohibited'],
            'decimals' => ['sometimes', 'integer', 'between:0,4'],
            'status'   => ['sometimes', 'in:0,1'],
            'sort'     => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function attributes(): array
    {
        return ['code' => '币种代码', 'name' => '币种名称', 'symbol' => '符号', 'rate' => '汇率', 'decimals' => '小数位', 'status' => '状态', 'sort' => '排序'];
    }
}
