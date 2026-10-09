<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PayStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel'    => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/'],
            'return_url' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'channel' => '支付方式',
        ];
    }
}
