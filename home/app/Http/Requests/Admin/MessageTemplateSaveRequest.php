<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 消息模板保存：只传 is_enabled 表示只切换开关；标题、正文留空表示使用内置默认模板 */
class MessageTemplateSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_enabled' => ['nullable', 'in:0,1'],
            'title'      => ['nullable', 'array'],
            'title.*'    => ['nullable', 'string', 'max:255'],
            'content'    => ['nullable', 'array'],
            'content.*'  => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return ['is_enabled' => '启用', 'title.*' => '邮件标题', 'content.*' => '邮件正文'];
    }
}
