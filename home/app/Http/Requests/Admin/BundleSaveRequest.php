<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BundleSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:100'],
            'reduce_amount' => ['required', 'numeric', 'min:0.01', 'lt:10000000'],
            'sku_ids'       => ['required', 'array', 'min:2', 'max:6'],
            'sku_ids.*'     => ['integer', 'min:1'],
            'start_at'      => ['nullable', 'date_format:Y-m-d H:i:s'],
            'end_at'        => ['nullable', 'date_format:Y-m-d H:i:s'],
            'status'        => ['nullable', 'in:0,1'],
            'sort'          => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => '套餐名称', 'reduce_amount' => '立减金额', 'sku_ids' => '套餐商品', 'start_at' => '开始时间', 'end_at' => '结束时间', 'status' => '状态', 'sort' => '排序'];
    }
}
