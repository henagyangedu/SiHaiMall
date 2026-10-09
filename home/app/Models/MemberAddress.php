<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class MemberAddress extends BaseModel
{
    use SoftDeletes;

    protected $table = 'member_address';
}
