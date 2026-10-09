<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Admin extends BaseModel
{
    use SoftDeletes;

    protected $table = 'admin';
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AdminRole::class, 'role_id');
    }

    /** role_id = 0 即超级管理员，不走权限表 */
    public function isSuper(): bool
    {
        return (int) $this->role_id === 0;
    }
}
