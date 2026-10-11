<?php

namespace App\Http\Requests\Open;

use Illuminate\Foundation\Http\FormRequest;

/** 开放接口各个列表共用的查询参数。每个列表只用到其中一部分，用不到的传了也不起作用 */
class ListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'updated_after'  => ['nullable', 'date'],
            'updated_before' => ['nullable', 'date'],
            'status'         => ['nullable', 'integer', 'min:0', 'max:9'],
            'pay_status'     => ['nullable', 'integer', 'min:0', 'max:9'],
            'trade_type'     => ['nullable', 'in:1,2'],
            'order_no'       => ['nullable', 'string', 'max:32'],
            'sku_sn'         => ['nullable', 'string', 'max:2000'],
            'goods_id'       => ['nullable', 'integer', 'min:1'],
            'page'           => ['nullable', 'integer', 'min:1'],
            'page_size'      => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
