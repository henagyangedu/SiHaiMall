<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminLog extends BaseModel
{
    protected $table = 'admin_log';

    const UPDATED_AT = null;

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id')->withTrashed();
    }
}
