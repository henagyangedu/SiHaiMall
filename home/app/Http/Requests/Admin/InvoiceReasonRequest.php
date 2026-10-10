<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 驳回发票申请、作废发票：都要填原因（买家看得到） */
class InvoiceReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:200']];
    }

    public function attributes(): array
    {
        return ['reason' => '原因'];
    }
}
