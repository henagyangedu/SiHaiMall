<?php

namespace App\Http\Requests\Admin;

use App\Services\Common\ExchangeRateService;
use Illuminate\Foundation\Http\FormRequest;

class CurrencyRateConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rate_source'     => ['required', 'in:' . implode(',', array_keys(ExchangeRateService::SOURCES))],
            'rate_markup'     => ['required', 'numeric', 'min:0', 'max:20', 'regex:/^\d+(\.\d{1,2})?$/'],
            'rate_max_change' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function attributes(): array
    {
        return ['rate_source' => '行情源', 'rate_markup' => '加价百分比', 'rate_max_change' => '单次最大变动'];
    }
}
