<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CartRemoveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'ids' => '购物车商品',
        ];
    }
}
