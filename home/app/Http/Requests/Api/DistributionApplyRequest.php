<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class DistributionApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'real_name' => ['required', 'string', 'max:60'],
            'remark'    => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['real_name' => '真实姓名', 'remark' => '申请说明'];
    }
}
