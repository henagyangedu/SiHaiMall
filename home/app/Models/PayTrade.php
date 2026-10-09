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

    /**
     * 真正的渠道交易号；还没拿到时返回空串。
     * 表上有 (channel, channel_trade_no, trade_type) 唯一约束，所以还没拿到渠道单号的支付单不能都留空，
     * 而是用商城自己的支付单号占位（见 PayService::start）。读渠道单号一律用这个方法，不要直接读字段。
     */
    public function channelNo(): string
    {
        $no = (string) $this->channel_trade_no;

        return $no === (string) $this->trade_no ? '' : $no;
    }

    /** raw_response 里存 JSON：redirect_url（复用收银台地址）、flag（需人工处理的原因） */
    public function rawData(): array
    {
        $j = json_decode((string) $this->raw_response, true);

        return is_array($j) ? $j : [];
    }
}
