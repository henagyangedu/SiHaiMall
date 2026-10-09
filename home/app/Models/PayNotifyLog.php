<?php

namespace App\Models;

class PayNotifyLog extends BaseModel
{
    public const WAIT = 0;
    public const OK = 1;
    public const FAIL = 2;
    public const DUP = 3;

    public const STATUS_TEXT = [0 => '待处理', 1 => '成功', 2 => '失败', 3 => '重复忽略'];

    protected $table = 'pay_notify_log';

    public $timestamps = false;
}
