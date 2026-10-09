<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends BaseModel
{
    use SoftDeletes;

    public const TYPE_ARTICLE = 1;
    public const TYPE_NOTICE = 2;
    public const TYPE_HELP = 3;

    public const TYPE_TEXT = [1 => '文章', 2 => '公告', 3 => '帮助'];

    /** 接口里用的类型名 → 类型值 */
    public const TYPE_CODES = ['article' => 1, 'notice' => 2, 'help' => 3];

    /** 有分类的类型（公告不分类） */
    public const WITH_CATEGORY = [self::TYPE_ARTICLE, self::TYPE_HELP];

    protected $table = 'article';

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
