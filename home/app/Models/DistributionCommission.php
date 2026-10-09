<?php

namespace App\Models;

class DistributionCommission extends BaseModel
{
    public const PENDING = 0;   // 待结算
    public const SETTLED = 1;   // 已结算
    public const VOID = 2;      // 已作废

    public const STATUS_TEXT = [0 => '待结算', 1 => '已结算', 2 => '已作废'];

    protected $table = 'distribution_commission';

    protected function casts(): array
    {
        return ['settled_at' => 'datetime'];
    }
}
