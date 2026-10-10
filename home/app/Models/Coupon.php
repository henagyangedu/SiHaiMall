<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends BaseModel
{
    use SoftDeletes;

    public const TYPE_REDUCE = 1;     // 满减

    public const TYPE_DISCOUNT = 2;   // 折扣

    public const SCOPE_ALL = 1;       // 全部商品

    public const SCOPE_CATEGORY = 2;  // 指定分类（含下级分类）

    public const SCOPE_GOODS = 3;     // 指定商品

    public const VALID_FIXED = 1;     // 固定时间段

    public const VALID_DAYS = 2;      // 领取后 N 天

    public const TYPE_TEXT = [1 => '满减券', 2 => '折扣券'];

    public const SCOPE_TEXT = [1 => '全部商品', 2 => '指定分类', 3 => '指定商品'];

    protected $table = 'coupon';

    protected function casts(): array
    {
        return [
            'scope_ids' => 'array', 'valid_start' => 'datetime', 'valid_end' => 'datetime',
            'receive_start' => 'datetime', 'receive_end' => 'datetime',
        ];
    }
}
