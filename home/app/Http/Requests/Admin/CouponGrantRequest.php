<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CouponGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 每一项是会员 ID、账号、手机号或邮箱
            'members'   => ['required', 'array', 'min:1', 'max:500'],
            'members.*' => ['string', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return ['members' => '会员'];
    }
}
