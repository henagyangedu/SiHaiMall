<?php

namespace App\Models;

class AdPosition extends BaseModel
{
    /** 预置广告位：前端页面写死了这些编码，不能删除或改编码 */
    public const BUILT_IN = ['home_banner', 'home_middle', 'category_top'];

    protected $table = 'ad_position';
}
