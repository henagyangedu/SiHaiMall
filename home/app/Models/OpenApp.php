<?php

namespace App\Models;

/** 开放接口的应用（一个外部系统一条） */
class OpenApp extends BaseModel
{
    protected $table = 'open_app';

    protected $hidden = ['key_hash', 'webhook_secret'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'last_used_at' => 'datetime', 'webhook_events' => 'array', 'webhook_secret' => 'encrypted'];
    }

    public function can(string $scope): bool
    {
        return in_array($scope, (array) $this->scopes, true);
    }
}
