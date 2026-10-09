<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 广告新增与编辑共用。默认语言的图片必填、跳转目标是否存在，在 AdService 校验 */
class AdSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'position_id' => ['required', 'integer', 'min:1'],
            'title'       => ['nullable', 'array'],
            'title.*'     => ['nullable', 'string', 'max:255'],
            'image'       => ['required', 'array'],
            'image.*'     => ['nullable', 'string', 'max:255'],
            'link_type'   => ['required', 'in:none,goods,category,article,url'],
            'link_value'  => ['nullable', 'string', 'max:500'],
            'sort'        => ['nullable', 'integer', 'min:0', 'max:9999'],
            'status'      => ['nullable', 'in:0,1'],
            'start_at'    => ['nullable', 'date_format:Y-m-d H:i:s'],
            'end_at'      => ['nullable', 'date_format:Y-m-d H:i:s'],
        ];
    }

    public function attributes(): array
    {
        return [
            'position_id' => '广告位', 'title.*' => '标题', 'image' => '图片', 'image.*' => '图片', 'link_type' => '跳转类型',
            'link_value' => '跳转目标', 'sort' => '排序', 'status' => '状态', 'start_at' => '开始时间', 'end_at' => '结束时间',
        ];
    }
}
