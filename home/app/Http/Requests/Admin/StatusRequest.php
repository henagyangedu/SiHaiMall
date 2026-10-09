<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 通用的“启用/禁用”切换请求 */
class StatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', 'in:0,1']];
    }

    public function attributes(): array
    {
        return ['status' => '状态'];
    }
}
