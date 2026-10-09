<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cart_ids'       => ['required_without:items', 'array', 'min:1', 'max:100'],
            'cart_ids.*'     => ['integer', 'min:1'],
            'items'          => ['required_without:cart_ids', 'array', 'min:1', 'max:100'],
            'items.*.sku_id' => ['required_with:items', 'integer', 'min:1'],
            'items.*.qty'    => ['required_with:items', 'integer', 'min:1', 'max:999'],
            'address_id'     => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'cart_ids' => '购物车商品', 'items' => '商品', 'address_id' => '收货地址',
        ];
    }
}
