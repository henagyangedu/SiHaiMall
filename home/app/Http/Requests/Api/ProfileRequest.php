<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nickname' => ['sometimes', 'string', 'max:60'],
            'avatar'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'gender'   => ['sometimes', 'integer', 'in:0,1,2'],
            'birthday' => ['sometimes', 'nullable', 'date', 'before:today'],
            'lang'     => ['sometimes', 'string', 'max:10'],
            'currency' => ['sometimes', 'string', 'max:10'],
        ];
    }

    public function attributes(): array
    {
        return [
            'nickname' => '昵称', 'avatar' => '头像', 'gender' => '性别', 'birthday' => '生日',
        ];
    }
}
