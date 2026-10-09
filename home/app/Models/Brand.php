<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends BaseModel
{
    use SoftDeletes;

    protected $table = 'brand';
}
