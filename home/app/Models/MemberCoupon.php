<?php

namespace App\Models;

class MemberCoupon extends BaseModel
{
    public const UNUSED = 0;

    public const USED = 1;

    public const EXPIRED = 2;

    public const SOURCE_SELF = 1;    // 买家自己领的

    public const SOURCE_ADMIN = 2;   // 后台发放的

    public const STATUS_TEXT = [0 => '未使用', 1 => '已使用', 2 => '已过期'];

    public const UPDATED_AT = null;

    protected $table = 'member_coupon';

    protected function casts(): array
    {
        return ['valid_start' => 'datetime', 'valid_end' => 'datetime', 'used_at' => 'datetime'];
    }
}
