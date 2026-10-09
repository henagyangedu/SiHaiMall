<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MemberAdjustPointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delta'  => ['required', 'integer', 'between:-10000000,10000000', 'not_in:0'],
            'remark' => ['required', 'string', 'max:200'],
        ];
    }

    public function attributes(): array
    {
        return ['delta' => '调整积分', 'remark' => '备注'];
    }
}
