<?php

namespace App\Http\Requests\Open;

use App\Services\Admin\OrderShipService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 发货、修改物流单号共用 */
class ShipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'express_code' => ['required', 'string', Rule::in(array_keys(OrderShipService::EXPRESS))],
            // 快递公司不在列表里时 express_code 传 OTHER，并在这里写名称
            'express_name' => ['nullable', 'string', 'max:60', 'required_if:express_code,OTHER'],
            'express_no'   => ['required', 'string', 'max:60'],
            // 只发其中几个商品行（订单详情里 items[].id）；不传 = 把还没发的全发了
            'item_ids'     => ['nullable', 'array', 'max:200'],
            'item_ids.*'   => ['integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return ['express_code' => '快递公司编码', 'express_name' => '快递公司名称', 'express_no' => '快递单号', 'item_ids' => '商品行'];
    }

    public function messages(): array
    {
        return [
            'express_code.in' => '快递公司编码不在支持的范围内，可用的编码见 express-companies 接口；不在列表里的传 OTHER 并填 express_name',
            'express_name.required_if' => '快递公司编码是 OTHER 时要填快递公司名称',
        ];
    }
}
