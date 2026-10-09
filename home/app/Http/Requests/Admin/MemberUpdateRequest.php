<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MemberUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nickname'   => ['nullable', 'string', 'max:60'],
            'gender'     => ['nullable', 'in:0,1,2'],
            'birthday'   => ['nullable', 'date_format:Y-m-d'],
            'level_id'   => ['required', 'integer', 'min:1'],
            'phone_area' => ['nullable', 'string', 'max:8', 'regex:/^\d*$/'],
            'phone'      => ['nullable', 'string', 'max:30', 'regex:/^[0-9\-\s]*$/'],
            'email'      => ['nullable', 'email', 'max:100'],
            'lang'       => ['nullable', 'string', 'max:10'],
            'currency'   => ['nullable', 'string', 'max:10'],
        ];
    }

    public function attributes(): array
    {
        return ['nickname' => '昵称', 'gender' => '性别', 'birthday' => '生日', 'level_id' => '会员等级', 'phone_area' => '区号', 'phone' => '手机号', 'email' => '邮箱', 'lang' => '语言', 'currency' => '币种'];
    }
}
