<?php

namespace App\Models;

class AdminToken extends BaseModel
{
    protected $table = 'admin_token';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'expires_at'   => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
