<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_no' => ['required', 'string', 'max:40'],
            'file_url'   => ['required', 'string', 'max:255'],
            // 不填就按“实付 − 已退款”开
            'amount'     => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
        ];
    }

    public function attributes(): array
    {
        return ['invoice_no' => '发票号码', 'file_url' => '发票文件', 'amount' => '开票金额'];
    }
}
