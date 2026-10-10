<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class WaybillAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'express_code' => ['required', 'string', 'max:20'],
            'partner_id'   => ['required', 'string', 'max:100'],
            // 修改时留空表示不改
            'partner_key'  => ['nullable', 'string', 'max:200'],
            'net'          => ['nullable', 'string', 'max:100'],
            'temp_id'      => ['nullable', 'string', 'max:100'],
            'remark'       => ['nullable', 'string', 'max:100'],
            'status'       => ['nullable', 'in:0,1'],
        ];
    }

    public function attributes(): array
    {
        return ['express_code' => '快递公司', 'partner_id' => '月结账号', 'partner_key' => '账号密码', 'net' => '网点', 'temp_id' => '面单模板编号', 'remark' => '备注', 'status' => '状态'];
    }
}
