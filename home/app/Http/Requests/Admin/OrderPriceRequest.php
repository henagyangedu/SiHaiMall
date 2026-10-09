<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['pay_amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/']];
    }

    public function attributes(): array
    {
        return ['pay_amount' => '应付金额'];
    }

    public function messages(): array
    {
        return ['pay_amount.regex' => '应付金额格式不正确，最多两位小数'];
    }
}
