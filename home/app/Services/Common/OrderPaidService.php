<?php

namespace App\Services\Common;

use App\Models\Order;
use App\Support\Exceptions\BusinessException;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * 订单「支付成功」的唯一处理入口：支付回调、主动查单补单、后台确认收款都调这里。
 * 幂等：订单已不是待支付时直接返回 false，不会重复加销量、重复扣库存。
 */
class OrderPaidService
{
    public function __construct(
        private OrderStatusService $status,
        private OrderStockService $stock,
        private ConfigService $config,
    ) {}

    /** @return bool true=本次完成了支付状态变更；false=订单已处理过 */
    public function markPaid(int $orderId, string $channel, int $opType = OrderStatus::OP_SYSTEM, int $opId = 0, string $remark = ''): bool
    {
        return DB::transaction(function () use ($orderId, $channel, $opType, $opId, $remark) {
            $order = Order::query()->lockForUpdate()->find($orderId) ?? throw new BusinessException('订单不存在');
            if ((int) $order->pay_status === OrderStatus::PAY_PAID || (int) $order->status !== OrderStatus::PENDING) {
                return false;
            }

            $items = DB::table('order_item')->where('order_id', $orderId)->get();

            // 「支付后扣库存」模式：此时才扣；库存不足会抛异常，由调用方（支付模块）转为自动退款
            if (($this->config->value('trade', 'stock_deduct_mode') ?? 'order') === 'pay') {
                $need = [];
                foreach ($items as $it) {
                    $need[(int) $it->sku_id] = ($need[(int) $it->sku_id] ?? 0) + (int) $it->qty;
                }
                $this->stock->deduct($need, $order->order_no);
            }

            $virtualOnly = !DB::table('order_address')->where('order_id', $orderId)->exists();
            $to = $virtualOnly ? OrderStatus::FINISHED : OrderStatus::TO_SHIP;

            $this->status->transit($order, [OrderStatus::PENDING], $to, $opType, $opId, $remark ?: ('支付成功：' . $channel), [
                'pay_status' => OrderStatus::PAY_PAID, 'pay_channel' => $channel, 'paid_at' => now(),
                'finished_at' => $virtualOnly ? now() : null,
            ]);

            foreach ($items as $it) {
                DB::table('goods_sku')->where('id', $it->sku_id)->increment('sales', (int) $it->qty);
                DB::table('goods')->where('id', $it->goods_id)->increment('sales', (int) $it->qty);
            }

            return true;
        });
    }
}
