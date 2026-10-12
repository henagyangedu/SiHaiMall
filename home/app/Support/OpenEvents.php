<?php

namespace App\Support;

/** 开放接口主动推送的事件。新增事件：在这里登记，在业务节点调 OpenWebhookService::emit，并同步改 docs/开放接口.md */
final class OpenEvents
{
    public const ORDER_PAID = 'order.paid';
    public const ORDER_SHIPPED = 'order.shipped';
    public const ORDER_COMPLETED = 'order.completed';
    public const ORDER_CLOSED = 'order.closed';
    public const REFUND_CREATED = 'refund.created';
    public const REFUND_COMPLETED = 'refund.completed';

    /** 后台“发送测试通知”用的事件，不用订阅、不要求权限 */
    public const PING = 'ping';

    /** 事件 => [给管理员看的说明, 应用要有的权限]。没有这个权限的应用收不到，也订阅不了 */
    public const ALL = [
        self::ORDER_PAID       => ['订单付款成功', OpenScopes::ORDER_READ],
        self::ORDER_SHIPPED    => ['订单发货（每发一个包裹一次）', OpenScopes::ORDER_READ],
        self::ORDER_COMPLETED  => ['订单完成', OpenScopes::ORDER_READ],
        self::ORDER_CLOSED     => ['订单取消或关闭', OpenScopes::ORDER_READ],
        self::REFUND_CREATED   => ['发起售后', OpenScopes::FINANCE_READ],
        self::REFUND_COMPLETED => ['退款完成', OpenScopes::FINANCE_READ],
    ];
}
