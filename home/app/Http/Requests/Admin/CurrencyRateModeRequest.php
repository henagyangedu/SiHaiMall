<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CurrencyRateModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['mode' => ['required', 'in:1,2']];
    }

    public function attributes(): array
    {
        return ['mode' => '汇率更新方式'];
    }
}
