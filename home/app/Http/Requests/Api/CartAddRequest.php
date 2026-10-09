<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CartAddRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku_id' => ['required', 'integer', 'min:1'],
            'qty'    => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'sku_id' => '商品规格', 'qty' => '数量',
        ];
    }
}
