<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderShipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'express_code' => ['required', 'string', 'max:40'],
            'express_name' => ['nullable', 'string', 'max:60'],
            'express_no'   => ['required', 'string', 'max:60'],
            'item_ids'     => ['nullable', 'array', 'max:200'],
            'item_ids.*'   => ['integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['express_code' => '快递公司', 'express_name' => '快递公司名称', 'express_no' => '快递单号', 'item_ids' => '发货商品'];
    }
}
