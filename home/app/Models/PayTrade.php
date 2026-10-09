<?php

namespace App\Models;

class PayTrade extends BaseModel
{
    public const PENDING = 0;
    public const SUCCESS = 1;
    public const FAILED = 2;
    public const CLOSED = 3;

    public const STATUS_TEXT = [0 => '待支付', 1 => '成功', 2 => '失败', 3 => '已关闭'];

    protected $table = 'pay_trade';

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    /** raw_response 里存 JSON：redirect_url（复用收银台地址）、flag（需人工处理的原因） */
    public function rawData(): array
    {
        $j = json_decode((string) $this->raw_response, true);

        return is_array($j) ? $j : [];
    }
}
