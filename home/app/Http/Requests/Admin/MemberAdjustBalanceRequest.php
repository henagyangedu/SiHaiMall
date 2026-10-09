<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MemberAdjustBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^-?\d{1,9}(\.\d{1,2})?$/'],
            'remark' => ['required', 'string', 'max:200'],
        ];
    }

    public function attributes(): array
    {
        return ['amount' => '调整金额', 'remark' => '备注'];
    }

    public function messages(): array
    {
        return ['amount.regex' => '调整金额格式不正确，最多两位小数'];
    }
}
