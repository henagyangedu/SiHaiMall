<?php

namespace App\Models;

class Distributor extends BaseModel
{
    public const PENDING = 0;   // 待审核
    public const NORMAL = 1;    // 正常
    public const REJECTED = 2;  // 已拒绝
    public const DISABLED = 3;  // 已禁用

    public const STATUS_TEXT = [0 => '待审核', 1 => '正常', 2 => '已拒绝', 3 => '已禁用'];

    public $incrementing = false;

    protected $table = 'distributor';

    protected $primaryKey = 'member_id';

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }
}
