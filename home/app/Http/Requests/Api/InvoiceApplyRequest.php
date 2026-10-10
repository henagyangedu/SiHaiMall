<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class InvoiceApplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title_type' => ['required', 'in:1,2'],
            'title'      => ['required', 'string', 'max:100'],
            // 企业必填，格式在服务里校验
            'tax_no'     => ['nullable', 'string', 'max:30'],
            'email'      => ['required', 'email', 'max:100'],
            'company_info'              => ['nullable', 'array'],
            'company_info.address'      => ['nullable', 'string', 'max:100'],
            'company_info.phone'        => ['nullable', 'string', 'max:30'],
            'company_info.bank'         => ['nullable', 'string', 'max:100'],
            'company_info.bank_account' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title_type' => '抬头类型', 'title' => '发票抬头', 'tax_no' => '纳税人识别号', 'email' => '收票邮箱',
            'company_info' => '开票资料', 'company_info.address' => '注册地址', 'company_info.phone' => '注册电话',
            'company_info.bank' => '开户银行', 'company_info.bank_account' => '银行账号',
        ];
    }
}
