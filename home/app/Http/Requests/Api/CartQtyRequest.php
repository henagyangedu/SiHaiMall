<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CartQtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qty' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'qty' => '数量',
        ];
    }
}
