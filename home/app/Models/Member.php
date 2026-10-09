<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends BaseModel
{
    use SoftDeletes;

    protected $table = 'member';

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['birthday' => 'date:Y-m-d', 'balance' => 'decimal:2'];
    }
}
