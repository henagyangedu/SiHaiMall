<?php

namespace App\Services\Api;

use App\Models\Cart;
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class CartService
{
    private const MAX_LINES = 100;
    private const MAX_QTY = 999;

    public function __construct(private CatalogService $catalog) {}

    public function list(int $memberId, string $lang, string $currency): array
    {
        $rows = Cart::query()->where('member_id', $memberId)->orderByDesc('id')->get();
        $briefs = $this->catalog->skuBriefs($rows->pluck('sku_id')->all(), $lang, $currency);

        $items = [];
        $total = '0.00';
        foreach ($rows as $r) {
            $b = $briefs[$r->sku_id] ?? null;
            if (!$b) {
                // 商品已被物理删除：当作失效项展示，用户可清理
                $items[] = ['id' => (int) $r->id, 'sku_id' => (int) $r->sku_id, 'qty' => (int) $r->qty, 'valid' => false, 'title' => '', 'spec_text' => '', 'image' => '', 'price' => '0.00', 'line_amount' => '0.00', 'stock' => 0];
                continue;
            }
            $valid = $b['valid'] && $b['stock'] > 0;
            $line = Money::mul($b['price'], (int) $r->qty);
            $items[] = [
                'id' => (int) $r->id, 'sku_id' => $b['sku_id'], 'goods_id' => $b['goods_id'], 'title' => $b['title'], 'spec_text' => $b['spec_text'],
                'image' => $b['image'], 'price' => $b['price'], 'qty' => (int) $r->qty, 'stock' => $b['stock'],
                'valid' => $valid, 'enough' => $b['stock'] >= (int) $r->qty, 'line_amount' => $line,
            ];
            if ($valid) {
                $total = bcadd($total, $line, 2);
            }
        }

        return ['items' => $items, 'valid_total' => $total, 'currency' => $currency, 'count' => count($items)];
    }

    /** 加入购物车：同 SKU 累加数量 */
    public function add(int $memberId, int $skuId, int $qty): void
    {
        $b = $this->catalog->skuBriefs([$skuId], '', '')[$skuId] ?? null;
        if (!$b || !$b['valid']) {
            throw new BusinessException('商品已下架或不存在');
        }

        DB::transaction(function () use ($memberId, $skuId, $qty, $b) {
            $row = Cart::query()->where('member_id', $memberId)->where('sku_id', $skuId)->lockForUpdate()->first();
            $new = ($row ? (int) $row->qty : 0) + $qty;
            if ($new > self::MAX_QTY) {
                throw new BusinessException('单个商品最多加入 ' . self::MAX_QTY . ' 件');
            }
            if ($new > $b['stock']) {
                throw new BusinessException('库存不足，当前库存 ' . $b['stock'] . ' 件');
            }
            if ($row) {
                $row->forceFill(['qty' => $new])->save();

                return;
            }
            if (Cart::query()->where('member_id', $memberId)->count() >= self::MAX_LINES) {
                throw new BusinessException('购物车已满，请先清理');
            }
            Cart::query()->create(['member_id' => $memberId, 'sku_id' => $skuId, 'qty' => $new]);
        });
    }

    public function setQty(int $memberId, int $id, int $qty): void
    {
        $row = Cart::query()->where('member_id', $memberId)->find($id) ?? throw new BusinessException('购物车商品不存在');
        $b = $this->catalog->skuBriefs([(int) $row->sku_id], '', '')[$row->sku_id] ?? null;
        if (!$b || !$b['valid']) {
            throw new BusinessException('商品已下架，请移除');
        }
        if ($qty > $b['stock']) {
            throw new BusinessException('库存不足，当前库存 ' . $b['stock'] . ' 件');
        }
        $row->forceFill(['qty' => $qty])->save();
    }

    /** @param int[] $ids */
    public function remove(int $memberId, array $ids): void
    {
        Cart::query()->where('member_id', $memberId)->whereIn('id', $ids)->delete();
    }

    /** 清理失效商品（下架 / 删除 / 无库存） */
    public function clearInvalid(int $memberId): int
    {
        $rows = Cart::query()->where('member_id', $memberId)->get();
        $briefs = $this->catalog->skuBriefs($rows->pluck('sku_id')->all(), '', '');
        $bad = $rows->filter(fn ($r) => !isset($briefs[$r->sku_id]) || !$briefs[$r->sku_id]['valid'] || $briefs[$r->sku_id]['stock'] <= 0)->pluck('id')->all();
        if ($bad !== []) {
            Cart::query()->whereIn('id', $bad)->delete();
        }

        return count($bad);
    }

    /**
     * 把购物车行转为下单明细，同时校验归属。
     * @param int[] $cartIds
     * @return array<int,array{sku_id:int,qty:int}>
     */
    public function linesFor(int $memberId, array $cartIds): array
    {
        $rows = Cart::query()->where('member_id', $memberId)->whereIn('id', $cartIds)->get();
        if ($rows->isEmpty()) {
            throw new BusinessException('请选择要结算的商品');
        }

        return $rows->map(fn ($r) => ['sku_id' => (int) $r->sku_id, 'qty' => (int) $r->qty])->all();
    }
}
