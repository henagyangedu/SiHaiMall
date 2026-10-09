<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TranslateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'default_route'            => ['required', 'array'],
            'default_route.primary'    => ['nullable', 'string', 'max:30'],
            'default_route.fallback'   => ['nullable', 'string', 'max:30'],
            'lang_routes'              => ['nullable', 'array'],
            'lang_routes.*.primary'    => ['nullable', 'string', 'max:30'],
            'lang_routes.*.fallback'   => ['nullable', 'string', 'max:30'],
            'daily_limit_chars'        => ['required', 'integer', 'min:0', 'max:100000000'],
            'prompt_extra'             => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['default_route' => '默认路由', 'daily_limit_chars' => '每日额度', 'prompt_extra' => '附加规则'];
    }
}
