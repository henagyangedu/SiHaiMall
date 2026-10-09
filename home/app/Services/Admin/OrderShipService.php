<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\Order;
use App\Models\Shipping;
use App\Services\Common\OrderStatusService;
use App\Support\Exceptions\BusinessException;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\DB;

/** 手工发货（填快递单号），支持分批发货。电子面单驱动接入后，在 ship() 里按 waybill_driver 取号即可 */
class OrderShipService
{
    /** 常用快递公司（发货设置模块完成后改为读库）。code 用于后续物流查询接口 */
    public const EXPRESS = [
        'SF' => '顺丰速运', 'ZTO' => '中通快递', 'YTO' => '圆通速递', 'STO' => '申通快递', 'YUNDA' => '韵达快递',
        'JTEXPRESS' => '极兔速递', 'EMS' => '中国邮政 EMS', 'DHL' => 'DHL', 'FEDEX' => 'FedEx', 'UPS' => 'UPS', 'USPS' => 'USPS', 'OTHER' => '其它',
    ];

    public function __construct(private OrderStatusService $status) {}

    public function expressOptions(): array
    {
        return collect(self::EXPRESS)->map(fn ($n, $c) => ['code' => $c, 'name' => $n])->values()->all();
    }

    /** @param array{express_code:string,express_name?:string,express_no:string,item_ids?:int[]} $d */
    public function ship(int $orderId, array $d, Admin $op): void
    {
        DB::transaction(function () use ($orderId, $d, $op) {
            $o = Order::query()->lockForUpdate()->find($orderId) ?? throw new BusinessException('订单不存在');
            // 部分退款后剩余的商品仍可发货；售后进行中的订单状态是“售后中”，要等售后处理完
            if ((int) $o->status !== OrderStatus::TO_SHIP || !in_array((int) $o->pay_status, [OrderStatus::PAY_PAID, OrderStatus::PAY_PART_REFUND], true)) {
                throw new BusinessException('只有已支付的待发货订单可以发货');
            }

            // 已全部退款的商品行不再发货
            $pending = DB::table('order_item')->where('order_id', $orderId)->where('ship_status', 0)->whereColumn('refund_qty', '<', 'qty')
                ->lockForUpdate()->pluck('id')->map(fn ($v) => (int) $v)->all();
            $pick = array_values(array_unique(array_map('intval', (array) ($d['item_ids'] ?? []))));
            $pick = $pick === [] ? $pending : $pick;
            if ($pick === [] || array_diff($pick, $pending) !== []) {
                throw new BusinessException('所选商品不存在或已发货');
            }

            [$code, $name] = $this->express($d);
            Shipping::query()->create([
                'order_id' => $orderId, 'express_code' => $code, 'express_name' => $name, 'express_no' => trim((string) $d['express_no']),
                'item_ids' => implode(',', $pick), 'shipped_at' => now(), 'created_at' => now(),
            ]);
            DB::table('order_item')->whereIn('id', $pick)->update(['ship_status' => 1]);

            if (count($pick) === count($pending)) {
                $this->status->transit($o, [OrderStatus::TO_SHIP], OrderStatus::SHIPPED, OrderStatus::OP_ADMIN, (int) $op->id, "已发货：{$name} {$d['express_no']}", ['shipped_at' => now()]);
            } else {
                $this->status->note($o, OrderStatus::OP_ADMIN, (int) $op->id, "部分发货：{$name} {$d['express_no']}（" . count($pick) . ' 件商品行）');
            }
        });
    }

    public function editExpress(int $shippingId, array $d, Admin $op): void
    {
        $s = Shipping::query()->find($shippingId) ?? throw new BusinessException('发货记录不存在');
        $o = Order::query()->find($s->order_id) ?? throw new BusinessException('订单不存在');
        if (!in_array((int) $o->status, [OrderStatus::SHIPPED, OrderStatus::TO_SHIP, OrderStatus::FINISHED], true)) {
            throw new BusinessException('当前订单状态不能修改物流信息');
        }
        [$code, $name] = $this->express($d);
        $s->update(['express_code' => $code, 'express_name' => $name, 'express_no' => trim((string) $d['express_no'])]);
        $this->status->note($o, OrderStatus::OP_ADMIN, (int) $op->id, "修改物流单号：{$name} {$d['express_no']}");
    }

    /** 到期自动确认收货：已发货超过 N 天的订单 → 已完成。返回处理数量 */
    public function autoReceive(int $days, int $limit = 200): int
    {
        $n = 0;
        Order::query()->where('status', OrderStatus::SHIPPED)->where('shipped_at', '<', now()->subDays($days))->orderBy('id')->limit($limit)->get()
            ->each(function (Order $o) use (&$n) {
                try {
                    DB::transaction(fn () => $this->status->transit($o, [OrderStatus::SHIPPED], OrderStatus::FINISHED, OrderStatus::OP_SYSTEM, 0, "发货超过 {$days} 天，系统自动确认收货", ['finished_at' => now()]));
                    $n++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $n;
    }

    /** @return array{0:string,1:string} */
    private function express(array $d): array
    {
        $code = (string) ($d['express_code'] ?? 'OTHER');
        $name = $code === 'OTHER' ? trim((string) ($d['express_name'] ?? '')) : (self::EXPRESS[$code] ?? trim((string) ($d['express_name'] ?? '')));
        if ($name === '') {
            throw new BusinessException('请填写快递公司名称');
        }

        return [$code, mb_substr($name, 0, 60)];
    }
}
