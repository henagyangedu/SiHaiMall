<?php

namespace App\Http\Requests\Open;

use Illuminate\Foundation\Http\FormRequest;

class StockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items'          => ['required', 'array', 'min:1', 'max:100'],
            // 规格用 sku_id 或 sku_sn（货号）指定，两个都传时用 sku_id
            'items.*.sku_id' => ['nullable', 'integer', 'min:1', 'required_without:items.*.sku_sn'],
            'items.*.sku_sn' => ['nullable', 'string', 'max:60', 'required_without:items.*.sku_id'],
            // set：把库存改成 qty；add：在现在的基础上加 qty（负数是减）。不传按 set
            'items.*.mode'   => ['nullable', 'in:set,add'],
            'items.*.qty'    => ['required', 'integer', 'min:-9999999', 'max:9999999'],
            'items.*.remark' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return ['items' => '库存列表', 'items.*.sku_id' => '规格 ID', 'items.*.sku_sn' => '货号', 'items.*.mode' => '修改方式', 'items.*.qty' => '数量', 'items.*.remark' => '备注'];
    }
}
