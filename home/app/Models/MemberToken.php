<?php

namespace App\Models;

class MemberToken extends BaseModel
{
    protected $table = 'member_token';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'last_used_at' => 'datetime'];
    }
}
