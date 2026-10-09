<?php

namespace App\Services\Common;

use App\Models\GoodsSku;
use App\Services\Admin\GoodsSkuService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/** 订单触发的库存变动（系统操作）。库存只能通过这里或后台 adjustStock 改，并且一定写 stock_log */
class OrderStockService
{
    public function __construct(private GoodsSkuService $skus) {}

    /**
     * 下单扣减。$need: [sku_id => qty]。按 sku_id 升序加锁，避免并发下单互相死锁。
     * 必须在事务内调用。
     */
    public function deduct(array $need, string $orderNo): void
    {
        ksort($need);
        $goodsIds = [];

        foreach ($need as $skuId => $qty) {
            $sku = GoodsSku::query()->lockForUpdate()->find($skuId) ?? throw new BusinessException('商品规格不存在');
            if ((int) $sku->stock < $qty) {
                throw new BusinessException('库存不足：' . ($sku->sku_sn ?: ('SKU#' . $skuId)) . '，剩余 ' . (int) $sku->stock);
            }
            $after = (int) $sku->stock - $qty;
            $sku->update(['stock' => $after]);
            $this->log((int) $sku->goods_id, (int) $skuId, 1, -$qty, $after, $orderNo, '下单扣减');
            $goodsIds[$sku->goods_id] = true;
        }

        foreach (array_keys($goodsIds) as $gid) {
            $this->skus->refreshTotals((int) $gid);
        }
    }

    /**
     * 取消/关闭回补：按流水里该订单「已扣减 - 已回补」的净额回补，重复调用不会多补。
     * $type：2 取消回补；3 售后回补。必须在事务内调用。
     */
    public function restore(string $orderNo, int $type = 2, string $remark = '取消回补'): void
    {
        $net = DB::table('stock_log')->where('ref_no', $orderNo)->whereIn('type', [1, 2, 3])
            ->groupBy('sku_id', 'goods_id')->selectRaw('sku_id, goods_id, sum(change_qty) as q')->get()
            ->filter(fn ($r) => (int) $r->q < 0)->sortBy('sku_id');

        $goodsIds = [];
        foreach ($net as $r) {
            $sku = GoodsSku::query()->lockForUpdate()->find($r->sku_id);
            if (!$sku) {
                continue;
            }
            $back = -(int) $r->q;
            $after = (int) $sku->stock + $back;
            $sku->update(['stock' => $after]);
            $this->log((int) $r->goods_id, (int) $r->sku_id, $type, $back, $after, $orderNo, $remark);
            $goodsIds[$r->goods_id] = true;
        }

        foreach (array_keys($goodsIds) as $gid) {
            $this->skus->refreshTotals((int) $gid);
        }
    }

    /**
     * 售后按数量回补单个 SKU（单品退款/退货）。回补量不超过该订单在这个 SKU 上尚未回补的净扣减量，重复调用不会多补。
     * 必须在事务内调用。
     */
    public function restoreQty(string $orderNo, int $goodsId, int $skuId, int $qty, string $remark = '售后回补'): void
    {
        $net = -(int) DB::table('stock_log')->where('ref_no', $orderNo)->where('sku_id', $skuId)->whereIn('type', [1, 2, 3])->sum('change_qty');
        $back = min($qty, max($net, 0));
        $sku = $back > 0 ? GoodsSku::query()->lockForUpdate()->find($skuId) : null;
        if (!$sku) {
            return;
        }
        $after = (int) $sku->stock + $back;
        $sku->update(['stock' => $after]);
        $this->log($goodsId, $skuId, 3, $back, $after, $orderNo, $remark);
        $this->skus->refreshTotals($goodsId);
    }

    private function log(int $goodsId, int $skuId, int $type, int $change, int $after, string $ref, string $remark): void
    {
        DB::table('stock_log')->insert([
            'goods_id' => $goodsId, 'sku_id' => $skuId, 'type' => $type, 'change_qty' => $change, 'after_qty' => $after,
            'ref_no' => $ref, 'operator' => '系统', 'remark' => $remark, 'created_at' => now(),
        ]);
    }
}
