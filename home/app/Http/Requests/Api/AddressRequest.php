<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:60'],
            'phone_area'   => ['nullable', 'string', 'max:8'],
            'phone'        => ['required', 'string', 'max:30'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'province'     => ['nullable', 'string', 'max:60'],
            'city'         => ['nullable', 'string', 'max:60'],
            'district'     => ['nullable', 'string', 'max:60'],
            'address'      => ['required', 'string', 'max:255'],
            'address2'     => ['nullable', 'string', 'max:255'],
            'postcode'     => ['nullable', 'string', 'max:20'],
            'is_default'   => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => '收件人', 'phone' => '电话', 'country_code' => '国家', 'address' => '详细地址', 'postcode' => '邮编',
        ];
    }
}
