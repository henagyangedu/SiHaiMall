<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\Order;
use App\Models\Shipping;
use App\Services\Common\ExpressTrackService;
use App\Services\Common\NotifyService;
use App\Services\Common\OrderStatusService;
use App\Support\Exceptions\BusinessException;
use App\Support\OrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** 发货，支持分批发货。两种方式：手工填快递单号（ship），或者取电子面单（shipByWaybill：向服务商取号成功后再走 ship） */
class OrderShipService
{
    /** 常用快递公司（发货设置模块完成后改为读库）。code 用于后续物流查询接口 */
    public const EXPRESS = [
        'SF' => '顺丰速运', 'ZTO' => '中通快递', 'YTO' => '圆通速递', 'STO' => '申通快递', 'YUNDA' => '韵达快递',
        'JTEXPRESS' => '极兔速递', 'EMS' => '中国邮政 EMS', 'DHL' => 'DHL', 'FEDEX' => 'FedEx', 'UPS' => 'UPS', 'USPS' => 'USPS', 'TNT' => 'TNT',
        // 跨境小包常用的几家（国内的物流查询服务商查不了，要启用 17TRACK）
        'CHINAPOST' => '中国邮政（国际小包）', 'YUNEXPRESS' => '云途物流', 'YANWEN' => '燕文物流', '4PX' => '递四方', 'CAINIAO' => '菜鸟国际',
        'OTHER' => '其它',
    ];

    public function __construct(private OrderStatusService $status, private NotifyService $notify, private ExpressTrackService $tracks, private WaybillService $waybills) {}

    /** 一个包裹的物流轨迹；$refresh 为 true 时重新向服务商查询（有最短间隔限制） */
    public function track(int $shippingId, bool $refresh): array
    {
        $s = Shipping::query()->find($shippingId) ?? throw new BusinessException('发货记录不存在');

        return $this->tracks->track($s, $refresh);
    }

    public function expressOptions(): array
    {
        return collect(self::EXPRESS)->map(fn ($n, $c) => ['code' => $c, 'name' => $n])->values()->all();
    }

    /**
     * 电子面单发货：先向服务商取号，成功后按取到的单号发货。
     * 取号是外部调用，不放在数据库事务里；所以先做一遍和 ship() 一样的检查，免得订单根本发不了货却白取一个号。
     * 极少数情况下取到号之后发货失败（比如这期间订单被别人发掉了）：单号记进错误日志，要到服务商后台手工取消，不自动取消。
     * @param array{express_code:string,item_ids?:int[],weight?:string|null} $d
     * @return array{express_no:string,label_url:string}
     */
    public function shipByWaybill(int $orderId, array $d, Admin $op): array
    {
        $o = Order::query()->find($orderId) ?? throw new BusinessException('订单不存在');
        if ((int) $o->status !== OrderStatus::TO_SHIP || !in_array((int) $o->pay_status, [OrderStatus::PAY_PAID, OrderStatus::PAY_PART_REFUND], true)) {
            throw new BusinessException('只有已支付的待发货订单可以发货');
        }
        $pending = DB::table('order_item')->where('order_id', $orderId)->where('ship_status', 0)->whereColumn('refund_qty', '<', 'qty')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $pick = array_values(array_unique(array_map('intval', (array) ($d['item_ids'] ?? []))));
        $pick = $pick === [] ? $pending : $pick;
        if ($pick === [] || array_diff($pick, $pending) !== []) {
            throw new BusinessException('所选商品不存在或已发货');
        }

        $w = $this->waybills->create($o, (string) $d['express_code'], $pick, isset($d['weight']) ? (string) $d['weight'] : null);
        try {
            $this->ship($orderId, ['express_code' => $w['express_code'], 'express_no' => $w['express_no'], 'item_ids' => $pick, 'waybill_task_id' => $w['task_id'], 'label_url' => $w['label_url']], $op);
        } catch (Throwable $e) {
            Log::error('电子面单已取号但发货失败，需要到服务商后台取消这张面单', ['order_no' => $o->order_no, 'express_code' => $w['express_code'], 'express_no' => $w['express_no'], 'task_id' => $w['task_id'], 'error' => mb_substr($e->getMessage(), 0, 300)]);
            throw new BusinessException("已取到快递单号 {$w['express_no']}，但发货没有成功：" . ($e instanceof BusinessException ? $e->getMessage() : '系统错误') . '。请到快递服务商后台取消这张面单后重试');
        }

        return ['express_no' => $w['express_no'], 'label_url' => $w['label_url']];
    }

    /**
     * @param array{express_code:string,express_name?:string,express_no:string,item_ids?:int[],waybill_task_id?:string,label_url?:string} $d 后两项只有电子面单发货时才有
     * @param string $via 不是管理员在后台操作时，写进订单日志的来源说明（如“开放接口（某某 ERP）”）
     */
    public function ship(int $orderId, array $d, Admin $op, string $via = ''): void
    {
        DB::transaction(function () use ($orderId, $d, $op, $via) {
            $via = $via === '' ? '' : "{$via}：";
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
                'waybill_task_id' => (string) ($d['waybill_task_id'] ?? ''), 'label_url' => (string) ($d['label_url'] ?? ''),
                'item_ids' => implode(',', $pick), 'shipped_at' => now(), 'created_at' => now(),
            ]);
            DB::table('order_item')->whereIn('id', $pick)->update(['ship_status' => 1]);
            $this->notify->send('order_shipped', (int) $o->member_id, [
                'order_no' => $o->order_no, 'express_name' => $name, 'express_no' => trim((string) $d['express_no']),
            ]);

            if (count($pick) === count($pending)) {
                $this->status->transit($o, [OrderStatus::TO_SHIP], OrderStatus::SHIPPED, OrderStatus::OP_ADMIN, (int) $op->id, "{$via}已发货：{$name} {$d['express_no']}", ['shipped_at' => now()]);
            } else {
                // 状态没变，但外部系统按更新时间拉订单，要让它看得到这次发货
                Order::query()->whereKey($o->id)->update(['updated_at' => now()]);
                $this->status->note($o, OrderStatus::OP_ADMIN, (int) $op->id, "{$via}部分发货：{$name} {$d['express_no']}（" . count($pick) . ' 件商品行）');
            }
        });
    }

    public function editExpress(int $shippingId, array $d, Admin $op, string $via = ''): void
    {
        $via = $via === '' ? '' : "{$via}：";
        $s = Shipping::query()->find($shippingId) ?? throw new BusinessException('发货记录不存在');
        $o = Order::query()->find($s->order_id) ?? throw new BusinessException('订单不存在');
        if (!in_array((int) $o->status, [OrderStatus::SHIPPED, OrderStatus::TO_SHIP, OrderStatus::FINISHED], true)) {
            throw new BusinessException('当前订单状态不能修改物流信息');
        }
        [$code, $name] = $this->express($d);
        // 换了单号或快递公司，之前缓存的物流轨迹就不对了，一并清掉
        // 手工改了单号，原来的电子面单也就对不上了
        $changed = $code !== (string) $s->express_code || trim((string) $d['express_no']) !== (string) $s->express_no;
        $s->update(['express_code' => $code, 'express_name' => $name, 'express_no' => trim((string) $d['express_no']), 'track_status' => 0, 'track_data' => null]
            + ($changed ? ['waybill_task_id' => '', 'label_url' => ''] : []));
        Order::query()->whereKey($o->id)->update(['updated_at' => now()]);
        $this->status->note($o, OrderStatus::OP_ADMIN, (int) $op->id, "{$via}修改物流单号：{$name} {$d['express_no']}");
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
