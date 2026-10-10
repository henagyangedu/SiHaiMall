<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MessageBroadcastSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'       => ['required', 'array'],
            'title.*'     => ['nullable', 'string', 'max:200'],
            'content'     => ['required', 'array'],
            'content.*'   => ['nullable', 'string', 'max:2000'],
            'target_type' => ['required', 'in:1,2,3'],
            'level_ids'   => ['nullable', 'array', 'max:50'],
            'level_ids.*' => ['integer', 'min:1'],
            // 每一项是会员 ID、账号、手机号或邮箱
            'members'     => ['nullable', 'array', 'max:500'],
            'members.*'   => ['string', 'max:100'],
            'link_type'   => ['nullable', 'in:goods,article,coupon'],
            'link_value'  => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => '标题', 'title.*' => '标题', 'content' => '正文', 'content.*' => '正文', 'target_type' => '发送对象',
            'level_ids' => '会员等级', 'members' => '会员', 'link_type' => '跳转类型', 'link_value' => '跳转的商品或文章',
        ];
    }
}
