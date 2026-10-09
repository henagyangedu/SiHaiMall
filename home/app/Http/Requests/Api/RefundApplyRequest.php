<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** 申请售后。order_item_id 为 0 或不传表示整单退款；金额不传则按可退上限 */
class RefundApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_item_id' => ['nullable', 'integer', 'min:0'],
            'qty'           => ['nullable', 'integer', 'min:1', 'max:9999', 'required_with:order_item_id'],
            'type'          => ['required', 'in:1,2'],
            'reason'        => ['required', 'string', 'max:255'],
            'images'        => ['nullable', 'array', 'max:6'],
            'images.*'      => ['string', 'max:500'],
            'amount'        => ['nullable', 'numeric', 'gt:0', 'lt:10000000000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'order_item_id' => '售后商品', 'qty' => '数量', 'type' => '售后类型', 'reason' => '申请原因',
            'images' => '凭证图片', 'images.*' => '凭证图片', 'amount' => '退款金额',
        ];
    }
}
