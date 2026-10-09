<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DistributionWithdrawPaidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['pay_remark' => ['nullable', 'string', 'max:255']];
    }

    public function attributes(): array
    {
        return ['pay_remark' => '打款凭证'];
    }
}
