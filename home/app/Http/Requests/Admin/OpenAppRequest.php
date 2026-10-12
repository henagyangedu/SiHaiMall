<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OpenAppRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:60'],
            'scopes'       => ['required', 'array', 'min:1'],
            'scopes.*'     => ['string', 'max:40'],
            'ip_whitelist' => ['nullable', 'string', 'max:600'],
            'status'       => ['nullable', 'in:0,1'],
            'remark'       => ['nullable', 'string', 'max:200'],
            'webhook_url'      => ['nullable', 'string', 'max:300'],
            'webhook_events'   => ['nullable', 'array'],
            'webhook_events.*' => ['string', 'max:40'],
            'webhook_status'   => ['nullable', 'in:0,1'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => '应用名称', 'scopes' => '权限', 'ip_whitelist' => 'IP 白名单', 'status' => '状态', 'remark' => '备注', 'webhook_url' => '接收地址', 'webhook_events' => '订阅的事件', 'webhook_status' => '主动推送'];
    }
}
