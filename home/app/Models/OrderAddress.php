<?php

namespace App\Models;

class OrderAddress extends BaseModel
{
    protected $table = 'order_address';

    protected $primaryKey = 'order_id';

    public $incrementing = false;

    public $timestamps = false;
}
