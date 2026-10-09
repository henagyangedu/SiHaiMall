<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderCancelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:200']];
    }

    public function attributes(): array
    {
        return ['reason' => '取消原因'];
    }
}
