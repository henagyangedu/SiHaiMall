<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 评价的两个操作共用：隐藏 / 恢复（status）、商家回复（reply，空串表示删除回复） */
class GoodsReviewActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', 'integer', 'in:0,1'],
            'reply'  => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['status' => '显示状态', 'reply' => '回复内容'];
    }
}
