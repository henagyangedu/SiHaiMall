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
            // 电子面单发货（waybill=1）时单号由系统去取，不用填
            'express_no'   => ['required_unless:waybill,1', 'nullable', 'string', 'max:60'],
            'waybill'      => ['nullable', 'in:0,1'],
            'weight'       => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            'item_ids'     => ['nullable', 'array', 'max:200'],
            'item_ids.*'   => ['integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['express_code' => '快递公司', 'express_name' => '快递公司名称', 'express_no' => '快递单号', 'item_ids' => '发货商品', 'waybill' => '发货方式', 'weight' => '重量'];
    }
}
