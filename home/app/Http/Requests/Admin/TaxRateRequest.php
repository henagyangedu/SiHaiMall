<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'country_code'     => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            'province'         => ['nullable', 'string', 'max:100'],
            'name'             => ['required', 'string', 'max:50'],
            // 百分数，最多 3 位小数，不到 100
            'rate'             => ['required', 'regex:/^\d{1,2}(\.\d{1,3})?$/', 'numeric', 'gt:0'],
            'included'         => ['nullable', 'in:0,1'],
            'shipping_taxable' => ['nullable', 'in:0,1'],
            'status'           => ['nullable', 'in:0,1'],
        ];
    }

    public function attributes(): array
    {
        return ['country_code' => '国家 / 地区', 'province' => '省 / 州', 'name' => '税名', 'rate' => '税率', 'included' => '计税方式', 'shipping_taxable' => '运费是否计税', 'status' => '状态'];
    }

    public function messages(): array
    {
        return ['country_code.regex' => '国家 / 地区要填两位字母的国家码', 'rate.regex' => '税率要填 0 到 100 之间的百分数，最多 3 位小数'];
    }
}
