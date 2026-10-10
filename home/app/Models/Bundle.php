<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/** 搭配套餐（商品在 bundle_item 表） */
class Bundle extends BaseModel
{
    use SoftDeletes;

    /** 一个套餐最少、最多几件商品 */
    public const MIN_ITEMS = 2;
    public const MAX_ITEMS = 6;

    protected $table = 'bundle';

    protected function casts(): array
    {
        return ['start_at' => 'datetime', 'end_at' => 'datetime'];
    }
}
