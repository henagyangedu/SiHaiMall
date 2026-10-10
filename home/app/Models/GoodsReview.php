<?php

namespace App\Models;

class GoodsReview extends BaseModel
{
    public const SHOWN = 1;

    public const HIDDEN = 0;

    /** 4 星及以上算好评，3 星中评，2 星及以下差评 */
    public const GOOD_FROM = 4;

    protected $table = 'goods_review';

    protected function casts(): array
    {
        return ['images' => 'array', 'replied_at' => 'datetime', 'append_images' => 'array', 'append_at' => 'datetime', 'append_replied_at' => 'datetime'];
    }
}
