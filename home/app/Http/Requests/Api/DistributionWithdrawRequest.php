<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/** 申请提现。account 是收款信息：name 户名、no 账号必填，bank 开户行等按提现方式选填 */
class DistributionWithdrawRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'       => ['required', 'numeric', 'gt:0', 'lt:10000000000'],
            'method'       => ['required', 'in:bank,paypal,alipay,wechat,other'],
            'account'      => ['required', 'array', 'max:6'],
            'account.name' => ['required', 'string', 'max:100'],
            'account.no'   => ['required', 'string', 'max:100'],
            'account.bank' => ['nullable', 'string', 'max:100', 'required_if:method,bank'],
            'account.*'    => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return ['account.bank.required_if' => '请填写开户行'];
    }

    public function attributes(): array
    {
        return [
            'amount' => '提现金额', 'method' => '提现方式', 'account' => '收款信息',
            'account.name' => '收款人姓名', 'account.no' => '收款账号', 'account.bank' => '开户行',
        ];
    }
}
