<?php

namespace App\Models;

class Shipping extends BaseModel
{
    protected $table = 'shipping';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['shipped_at' => 'datetime'];
    }
}
