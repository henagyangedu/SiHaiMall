<?php

namespace App\Models;

class MemberBalanceLog extends BaseModel
{
    protected $table = 'member_balance_log';

    public $timestamps = false;

    public const TYPE_TEXT = [1 => '后台调整', 2 => '充值', 3 => '消费', 4 => '退款'];
}
