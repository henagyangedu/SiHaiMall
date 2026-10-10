<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UploadInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']]; // 5MB
    }

    public function attributes(): array
    {
        return ['file' => '发票文件'];
    }
}
