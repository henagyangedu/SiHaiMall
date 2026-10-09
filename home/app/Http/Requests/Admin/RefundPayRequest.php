<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 执行退款：offline=1 表示已通过线下或渠道后台退回，只登记不调用支付渠道 */
class RefundPayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['offline' => ['nullable', 'in:0,1']];
    }

    public function attributes(): array
    {
        return ['offline' => '退款方式'];
    }
}
