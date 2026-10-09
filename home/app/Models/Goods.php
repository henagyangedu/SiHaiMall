<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Goods extends BaseModel
{
    use SoftDeletes;

    protected $table = 'goods';
}
