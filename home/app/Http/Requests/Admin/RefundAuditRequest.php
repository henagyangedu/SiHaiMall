<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 售后审核：pass=1 同意（可改退款金额），pass=0 拒绝（必须填原因） */
class RefundAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pass'          => ['required', 'in:0,1'],
            'amount'        => ['nullable', 'numeric', 'gt:0', 'lt:10000000000'],
            'reject_reason' => ['nullable', 'string', 'max:255', 'required_if:pass,0'],
        ];
    }

    public function messages(): array
    {
        return ['reject_reason.required_if' => '请填写拒绝原因'];
    }

    public function attributes(): array
    {
        return ['pass' => '审核结果', 'amount' => '退款金额', 'reject_reason' => '拒绝原因'];
    }
}
