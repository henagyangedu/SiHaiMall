<?php

namespace App\Models;

class DistributionLog extends BaseModel
{
    public const SETTLE = 1;
    public const REFUND = 2;
    public const WITHDRAW = 3;
    public const WITHDRAW_BACK = 4;
    public const ADJUST = 5;

    public const TYPE_TEXT = [1 => '佣金结算', 2 => '退款扣回', 3 => '提现申请', 4 => '提现驳回退还', 5 => '后台调整'];

    public $timestamps = false;

    protected $table = 'distribution_log';
}
