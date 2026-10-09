<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Ad extends BaseModel
{
    use SoftDeletes;

    public const LINK_TYPES = ['none' => '不跳转', 'goods' => '商品', 'category' => '商品分类', 'article' => '文章', 'url' => '自定义链接'];

    protected $table = 'ad';

    protected function casts(): array
    {
        return ['start_at' => 'datetime', 'end_at' => 'datetime'];
    }
}
