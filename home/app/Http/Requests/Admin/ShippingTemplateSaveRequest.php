<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 运费模板新增与编辑共用。地区之间是否冲突等业务校验在 ShippingTemplateService。 */
class ShippingTemplateSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                    => ['required', 'string', 'max:100'],
            'charge_type'             => ['required', 'in:1,2,3'],
            'is_free_default'         => ['nullable', 'in:0,1'],

            'regions'                 => ['nullable', 'array', 'max:100'],
            'regions.*.countries'     => ['required', 'array', 'min:1', 'max:60'],
            'regions.*.countries.*'   => ['required', 'string', 'regex:/^(\*|[A-Za-z]{2})$/'],
            'regions.*.provinces'     => ['nullable', 'array', 'max:60'],
            'regions.*.provinces.*'   => ['required', 'string', 'max:60'],
            'regions.*.first_unit'    => ['required', 'numeric', 'gt:0', 'lt:1000000'],
            'regions.*.first_fee'     => ['required', 'numeric', 'min:0', 'lt:10000000000'],
            'regions.*.step_unit'     => ['nullable', 'numeric', 'min:0', 'lt:1000000'],
            'regions.*.step_fee'      => ['nullable', 'numeric', 'min:0', 'lt:10000000000'],
            'regions.*.free_amount'   => ['nullable', 'numeric', 'min:0', 'lt:10000000000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => '模板名称', 'charge_type' => '计费方式', 'is_free_default' => '默认包邮', 'regions' => '配送地区规则',
            'regions.*.countries' => '国家/地区', 'regions.*.countries.*' => '国家/地区',
            'regions.*.provinces' => '省/州', 'regions.*.provinces.*' => '省/州',
            'regions.*.first_unit' => '首件/首重/首体积', 'regions.*.first_fee' => '首费',
            'regions.*.step_unit' => '续件/续重/续体积', 'regions.*.step_fee' => '续费', 'regions.*.free_amount' => '满额包邮金额',
        ];
    }
}
