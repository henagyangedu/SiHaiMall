<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class ShippingTemplate extends BaseModel
{
    use SoftDeletes;

    /** 计费方式 */
    public const CHARGE_PIECE = 1;
    public const CHARGE_WEIGHT = 2;
    public const CHARGE_VOLUME = 3;

    protected $table = 'shipping_template';
}
