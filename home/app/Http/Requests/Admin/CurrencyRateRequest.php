<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CurrencyRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['rate' => ['required', 'numeric', 'gt:0', 'lt:1000000000']];
    }

    public function attributes(): array
    {
        return ['rate' => '汇率'];
    }
}
