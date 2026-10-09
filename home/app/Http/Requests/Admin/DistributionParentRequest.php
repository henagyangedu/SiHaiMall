<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 调整会员的上级：parent_id=0 表示清除上级 */
class DistributionParentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['parent_id' => ['required', 'integer', 'min:0']];
    }

    public function attributes(): array
    {
        return ['parent_id' => '上级会员 ID'];
    }
}
