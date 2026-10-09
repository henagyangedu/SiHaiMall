<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderRemarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['admin_remark' => ['nullable', 'string', 'max:500']];
    }

    public function attributes(): array
    {
        return ['admin_remark' => '商家备注'];
    }
}
