<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class RefundReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'express'    => ['required', 'string', 'max:60'],
            'express_no' => ['required', 'string', 'max:60'],
        ];
    }

    public function attributes(): array
    {
        return ['express' => '快递公司', 'express_no' => '快递单号'];
    }
}
