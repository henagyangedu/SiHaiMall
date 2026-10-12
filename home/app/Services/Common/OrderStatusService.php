<?php

namespace App\Services\Common;

use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Support\Exceptions\BusinessException;
use App\Support\OpenEvents;
use App\Support\OrderStatus;

/** 订单状态流转唯一入口：带前置状态校验（乐观锁）并写状态日志。必须在事务内调用 */
class OrderStatusService
{
    public function __construct(private OrderPointsService $points, private OpenWebhookService $webhooks) {}

    /**
     * @param int[] $from 允许的当前状态
     * @param array<string,mixed> $extra 同时更新的其它字段（如 paid_at、cancel_reason）
     */
    public function transit(Order $order, array $from, int $to, int $opType, int $opId, string $remark = '', array $extra = []): void
    {
        $affected = Order::query()->whereKey($order->id)->whereIn('status', $from)
            ->update(['status' => $to, 'updated_at' => now()] + $extra);

        if ($affected !== 1) {
            $now = Order::query()->whereKey($order->id)->value('status');
            throw new BusinessException('订单状态已变化（当前：' . (OrderStatus::TEXT[(int) $now] ?? '未知') . '），请刷新后重试');
        }

        OrderStatusLog::query()->create([
            'order_id' => $order->id, 'from_status' => (int) $order->status, 'to_status' => $to,
            'operator_type' => $opType, 'operator_id' => $opId, 'remark' => mb_substr($remark, 0, 250), 'created_at' => now(),
        ]);

        $prev = (int) $order->status;
        $order->refresh();

        // 订单完成即发放消费积分。放在这里是因为“完成”有多个入口（买家确认、后台确认、自动收货、虚拟商品支付即完成），同一订单只会发一次
        if ($to === OrderStatus::FINISHED) {
            $this->points->earn($order);
        }

        // 给外部系统的通知（开放接口的主动推送）。订单状态只从这里改，所以挂在这里不会漏；发货的通知在 OrderShipService 里（部分发货不改状态）
        $closed = in_array($to, [OrderStatus::CANCELLED, OrderStatus::CLOSED], true);
        if ($prev === OrderStatus::PENDING && !$closed) {
            $this->webhooks->order(OpenEvents::ORDER_PAID, $order);
        }
        // 售后结束后回到“已完成”的订单之前已经通知过，不再发
        if ($to === OrderStatus::FINISHED && $prev !== OrderStatus::AFTER_SALE) {
            $this->webhooks->order(OpenEvents::ORDER_COMPLETED, $order);
        }
        if ($closed) {
            $this->webhooks->order(OpenEvents::ORDER_CLOSED, $order);
        }
    }

    /** 只写日志（状态不变，如改价、备注） */
    public function note(Order $order, int $opType, int $opId, string $remark): void
    {
        OrderStatusLog::query()->create([
            'order_id' => $order->id, 'from_status' => (int) $order->status, 'to_status' => (int) $order->status,
            'operator_type' => $opType, 'operator_id' => $opId, 'remark' => mb_substr($remark, 0, 250), 'created_at' => now(),
        ]);
    }
}
