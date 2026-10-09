<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends BaseModel
{
    use SoftDeletes;

    protected $table = 'order';

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'shipped_at' => 'datetime', 'finished_at' => 'datetime', 'expire_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function address(): HasOne
    {
        return $this->hasOne(OrderAddress::class, 'order_id');
    }
}
