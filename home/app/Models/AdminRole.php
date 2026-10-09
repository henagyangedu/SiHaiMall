<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminRole extends BaseModel
{
    protected $table = 'admin_role';

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class, 'role_id');
    }
}
