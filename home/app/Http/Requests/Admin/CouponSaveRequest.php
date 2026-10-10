<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** 新建 / 编辑优惠券。字段之间的关系（满减金额小于门槛、指定范围时必须选范围等）在 Admin\CouponService 里检查 */
class CouponSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:100'],
            'type'          => ['required', 'in:1,2'],
            'threshold'     => ['nullable', 'numeric', 'min:0', 'lt:10000000'],
            'amount'        => ['nullable', 'numeric', 'min:0', 'lt:10000000'],
            'discount'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_amount'    => ['nullable', 'numeric', 'min:0', 'lt:10000000'],
            'scope'         => ['required', 'in:1,2,3'],
            'scope_ids'     => ['nullable', 'array', 'max:200'],
            'scope_ids.*'   => ['integer', 'min:1'],
            'total'         => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'per_member'    => ['nullable', 'integer', 'min:1', 'max:100'],
            'valid_type'    => ['required', 'in:1,2'],
            'valid_start'   => ['nullable', 'date_format:Y-m-d H:i:s'],
            'valid_end'     => ['nullable', 'date_format:Y-m-d H:i:s'],
            'valid_days'    => ['nullable', 'integer', 'min:0', 'max:3650'],
            'receive_start' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'receive_end'   => ['nullable', 'date_format:Y-m-d H:i:s'],
            'is_public'     => ['nullable', 'in:0,1'],
            'status'        => ['nullable', 'in:0,1'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => '券名称', 'type' => '类型', 'threshold' => '使用门槛', 'amount' => '满减金额', 'discount' => '折扣', 'max_amount' => '最高减免',
            'scope' => '适用范围', 'scope_ids' => '适用的分类或商品', 'total' => '发行总量', 'per_member' => '每人限领', 'valid_type' => '有效期类型',
            'valid_start' => '有效期开始时间', 'valid_end' => '有效期结束时间', 'valid_days' => '有效天数',
            'receive_start' => '领取开始时间', 'receive_end' => '领取结束时间', 'is_public' => '是否公开领取', 'status' => '状态',
        ];
    }
}
