<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 商家代买家发起售后。不传 order_item_id 表示整单退款；amount 不传则按系统算出的可退上限 */
class RefundApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_item_id' => ['nullable', 'integer', 'min:1'],
            'qty'           => ['nullable', 'integer', 'min:1', 'required_with:order_item_id'],
            'type'          => ['required', 'in:1,2'],
            'reason'        => ['required', 'string', 'max:200'],
            'amount'        => ['nullable', 'numeric', 'gt:0', 'lt:10000000000'],
        ];
    }

    public function attributes(): array
    {
        return ['order_item_id' => '售后商品', 'qty' => '数量', 'type' => '售后类型', 'reason' => '原因', 'amount' => '退款金额'];
    }
}
