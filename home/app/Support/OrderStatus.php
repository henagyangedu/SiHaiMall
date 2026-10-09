<?php

namespace App\Support;

/** 订单状态、支付状态常量。状态流转只能走 OrderStatusService，不要在别处直接改 status */
final class OrderStatus
{
    public const PENDING = 0;     // 待支付
    public const TO_SHIP = 1;     // 待发货
    public const SHIPPED = 2;     // 待收货
    public const FINISHED = 3;    // 已完成
    public const CANCELLED = 4;   // 已取消（买家/后台主动）
    public const AFTER_SALE = 5;  // 售后中
    public const CLOSED = 6;      // 已关闭（超时未支付）

    public const TEXT = [0 => '待支付', 1 => '待发货', 2 => '待收货', 3 => '已完成', 4 => '已取消', 5 => '售后中', 6 => '已关闭'];

    public const PAY_UNPAID = 0;
    public const PAY_PAID = 1;
    public const PAY_PART_REFUND = 2;
    public const PAY_REFUNDED = 3;

    public const PAY_TEXT = [0 => '未支付', 1 => '已支付', 2 => '部分退款', 3 => '已退款'];

    public const OP_SYSTEM = 0;
    public const OP_MEMBER = 1;
    public const OP_ADMIN = 2;
}
