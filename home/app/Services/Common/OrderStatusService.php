<?php

namespace App\Services\Common;

use App\Models\Order;
use App\Models\OrderStatusLog;
use App\Support\Exceptions\BusinessException;
use App\Support\OrderStatus;

/** 订单状态流转唯一入口：带前置状态校验（乐观锁）并写状态日志。必须在事务内调用 */
class OrderStatusService
{
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

        $order->refresh();
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
