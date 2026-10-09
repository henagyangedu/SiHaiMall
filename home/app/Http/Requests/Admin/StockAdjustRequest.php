<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StockAdjustRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku_id' => ['nullable', 'integer', 'min:1'],
            'change' => ['required', 'integer', 'between:-99999999,99999999'],
            'remark' => ['nullable', 'string', 'max:250'],
        ];
    }

    public function attributes(): array
    {
        return ['sku_id' => '规格', 'change' => '调整数量', 'remark' => '备注'];
    }
}
