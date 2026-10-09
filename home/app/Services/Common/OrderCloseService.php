<?php

namespace App\Services\Common;

use App\Models\Order;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\DB;

/** 未支付订单的取消/超时关闭：改状态 + 回补库存 + 作废待支付流水，同一事务 */
class OrderCloseService
{
    public function __construct(private OrderStatusService $status, private OrderStockService $stock) {}

    /** @param int $to OrderStatus::CANCELLED（主动取消）或 OrderStatus::CLOSED（超时关闭） */
    public function closeUnpaid(Order $order, int $to, int $opType, int $opId, string $reason): void
    {
        DB::transaction(function () use ($order, $to, $opType, $opId, $reason) {
            $this->status->transit($order, [OrderStatus::PENDING], $to, $opType, $opId, $reason, ['cancel_reason' => mb_substr($reason, 0, 250)]);
            $this->stock->restore($order->order_no, 2, $to === OrderStatus::CLOSED ? '超时关闭回补' : '取消回补');
            // 待支付的支付单作废，防止用户稍后在渠道侧付款后无法对应订单（已付款的回调由支付模块按「已关闭订单收款」走退款）
            DB::table('pay_trade')->where('order_id', $order->id)->where('trade_type', 1)->where('status', 0)
                ->update(['status' => 3, 'updated_at' => now()]);
        });
    }

    /** 关闭所有已超时的未支付订单，返回处理数量。每单独立事务，一单失败不影响其它 */
    public function closeExpired(int $limit = 200): int
    {
        $n = 0;
        Order::query()->where('status', OrderStatus::PENDING)->where('expire_at', '<', now())->orderBy('id')->limit($limit)->get()
            ->each(function (Order $o) use (&$n) {
                try {
                    $this->closeUnpaid($o, OrderStatus::CLOSED, OrderStatus::OP_SYSTEM, 0, '超时未支付自动关闭');
                    $n++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $n;
    }
}
