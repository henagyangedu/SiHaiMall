<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 分销员申请审核、提现审核共用：pass=1 通过，pass=0 拒绝（必须填原因） */
class DistributionAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pass'          => ['required', 'in:0,1'],
            'reject_reason' => ['nullable', 'string', 'max:255', 'required_if:pass,0'],
        ];
    }

    public function messages(): array
    {
        return ['reject_reason.required_if' => '请填写拒绝原因'];
    }

    public function attributes(): array
    {
        return ['pass' => '审核结果', 'reject_reason' => '拒绝原因'];
    }
}
