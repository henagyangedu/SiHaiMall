<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MemberLevelSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'array'],
            'name.*'      => ['nullable', 'string', 'max:50'],
            'level_no'    => ['required', 'integer', 'min:1', 'max:999'],
            'icon'        => ['nullable', 'string', 'max:255'],
            'need_points' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'discount'    => ['nullable', 'numeric', 'min:1', 'max:100'],
            'status'      => ['nullable', 'in:0,1'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => '等级名称', 'name.*' => '等级名称', 'level_no' => '等级序号', 'icon' => '图标', 'need_points' => '升级所需积分', 'discount' => '折扣', 'status' => '状态'];
    }
}
