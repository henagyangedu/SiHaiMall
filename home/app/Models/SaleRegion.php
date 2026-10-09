<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class SaleRegion extends BaseModel
{
    use SoftDeletes;

    /** 覆盖范围 */
    public const SCOPE_CN = 1;
    public const SCOPE_OVERSEAS = 2;

    protected $table = 'sale_region';
}
