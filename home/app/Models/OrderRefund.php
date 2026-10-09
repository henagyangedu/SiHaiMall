<?php

namespace App\Models;

class OrderRefund extends BaseModel
{
    public const TYPE_REFUND = 1;         // 仅退款
    public const TYPE_RETURN = 2;         // 退货退款

    public const PENDING = 0;             // 待审核
    public const WAIT_RETURN = 1;         // 同意，待买家退货
    public const WAIT_RECEIVE = 2;        // 买家已寄回，待商家确认收货
    public const REFUNDING = 3;           // 退款中（待执行退款或渠道处理中）
    public const DONE = 4;                // 已完成
    public const REJECTED = 5;            // 已拒绝
    public const CANCELLED = 6;           // 买家已撤销

    /** 进行中的状态：占用可退数量和可退金额 */
    public const ACTIVE = [self::PENDING, self::WAIT_RETURN, self::WAIT_RECEIVE, self::REFUNDING];

    public const TYPE_TEXT = [1 => '仅退款', 2 => '退货退款'];
    public const STATUS_TEXT = [0 => '待审核', 1 => '待买家退货', 2 => '待确认收货', 3 => '退款中', 4 => '已完成', 5 => '已拒绝', 6 => '已撤销'];

    protected $table = 'order_refund';

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }
}
