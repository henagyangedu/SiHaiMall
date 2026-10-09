<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 销售区域新增与编辑共用。省市是否在标准名单内、地区是否与其他区域冲突在 SaleRegionService 校验。 */
class SaleRegionSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:100'],
            'scope'            => ['required', 'in:1,2'],
            'currency'         => ['required', 'string', 'max:10'],
            'sort'             => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status'           => ['nullable', 'in:0,1'],

            'areas'            => ['nullable', 'array', 'max:40'],
            'areas.*.province' => ['required', 'string', 'max:60'],
            'areas.*.cities'   => ['nullable', 'array', 'max:60'],
            'areas.*.cities.*' => ['required', 'string', 'max:60'],

            'countries'        => ['nullable', 'array', 'max:250'],
            'countries.*'      => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => '区域名称', 'scope' => '区域类型', 'currency' => '定价币种', 'sort' => '排序', 'status' => '状态',
            'areas' => '覆盖省市', 'areas.*.province' => '省份', 'areas.*.cities' => '城市', 'areas.*.cities.*' => '城市',
            'countries' => '国家/地区', 'countries.*' => '国家/地区',
        ];
    }
}
