<?php

namespace App\Models;

/** 开放接口的推送记录（一条通知发给一个应用） */
class OpenWebhook extends BaseModel
{
    public const PENDING = 0;
    public const SUCCESS = 1;
    public const FAILED = 2;

    public const STATUS_TEXT = [0 => '待发送', 1 => '成功', 2 => '失败'];

    protected $table = 'open_webhook';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['next_at' => 'datetime', 'created_at' => 'datetime', 'delivered_at' => 'datetime'];
    }
}
