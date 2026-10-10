<?php

namespace App\Services\Common;

use App\Models\Bundle;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * 搭配套餐的规则：订单里凑齐一套就立减一笔钱。结算预览和下单共用 quote()，不要在别处自己算。
 *
 * - 套餐绑定的是具体规格；订单里这些规格每样至少 1 件算凑齐一套，能凑几套减几次。
 * - 只用于基准币种（人民币）的订单；立减金额固定，不随区域价变。
 * - 一个规格在多个套餐里时，先凑立减金额大的；一件商品只算进一个套餐。
 * - 减的钱按单价占比摊到这几件商品上（shares），后面的会员折扣、优惠券、积分都按摊完之后的金额算。
 * - 立减金额不会把一套商品减到 0：每件至少留 0.01。
 */
class BundleService
{
    public function __construct(private LocaleService $locale) {}

    /**
     * 这一单能享受多少套餐优惠。
     * @param array<int,array{qty:int,unit:string}> $lines 规格 ID => 数量和单价（下单币种）
     * @return array{amount:string,applied:array<int,array{id:int,name:string,sets:int,amount:string}>,shares:array<int,string>}
     *         amount 总共减多少；applied 用了哪些套餐；shares 每个规格摊到多少（没摊到的不在里面）
     */
    public function quote(array $lines, string $currency): array
    {
        $out = ['amount' => '0.00', 'applied' => [], 'shares' => []];
        if (count($lines) < Bundle::MIN_ITEMS || strtoupper($currency) !== strtoupper($this->locale->baseCurrency())) {
            return $out;
        }

        $remain = array_map(fn ($l) => (int) $l['qty'], $lines);
        foreach ($this->active(array_keys($lines)) as $b) {
            $skuIds = $b['sku_ids'];
            $sets = min(array_map(fn ($id) => $remain[$id] ?? 0, $skuIds));
            if ($sets <= 0) {
                continue;
            }
            $units = array_map(fn ($id) => (string) $lines[$id]['unit'], $skuIds);
            $unitSum = array_reduce($units, fn ($c, $u) => bcadd($c, $u, 2), '0.00');
            $per = self::perSet((string) $b['reduce_amount'], $units);
            if (bccomp($per, '0', 2) <= 0) {
                continue;
            }

            $amount = bcmul($per, (string) $sets, 2);
            // 按单价占比摊到每个规格，最后一个拿余数，保证加起来等于 amount
            $left = $amount;
            foreach ($skuIds as $i => $id) {
                $share = $i === count($skuIds) - 1 ? $left : Money::round(bcdiv(bcmul($amount, (string) $lines[$id]['unit'], 6), $unitSum, 6), 2);
                if (bccomp($share, $left, 2) > 0) {
                    $share = $left;
                }
                $left = bcsub($left, $share, 2);
                $out['shares'][$id] = bcadd($out['shares'][$id] ?? '0.00', $share, 2);
                $remain[$id] -= $sets;
            }
            $out['amount'] = bcadd($out['amount'], $amount, 2);
            $out['applied'][] = ['id' => (int) $b['id'], 'name' => (string) $b['name'], 'sets' => $sets, 'amount' => $amount];
        }

        return $out;
    }

    /**
     * 凑齐一套实际减多少：就是套餐的立减金额，但每件商品至少留 0.01（区域价很低时才会碰到这个上限）。
     * @param string[] $units 这一套里每件商品的单价
     */
    public static function perSet(string $reduce, array $units): string
    {
        $cap = bcsub(array_reduce($units, fn ($c, $u) => bcadd($c, (string) $u, 2), '0.00'), bcmul('0.01', (string) count($units), 2), 2);
        $per = Money::round($reduce, 2);
        if (bccomp($per, $cap, 2) > 0) {
            $per = $cap;
        }

        return bccomp($per, '0', 2) > 0 ? $per : '0.00';
    }

    /**
     * 现在生效的套餐里，商品全部在给定规格范围内的那些；立减金额大的在前。
     * @param int[] $skuIds
     * @return array<int,array{id:int,name:string,reduce_amount:string,sku_ids:int[]}>
     */
    public function active(array $skuIds): array
    {
        $ids = DB::table('bundle_item')->whereIn('sku_id', $skuIds)->distinct()->pluck('bundle_id')->all();

        return array_values(array_filter($this->load($ids), fn ($b) => array_diff($b['sku_ids'], $skuIds) === []));
    }

    /**
     * 包含某个商品的生效套餐（商品详情页用）。
     * @return array<int,array{id:int,name:string,reduce_amount:string,sku_ids:int[]}>
     */
    public function forGoods(int $goodsId): array
    {
        return $this->load(DB::table('bundle_item')->where('goods_id', $goodsId)->distinct()->pluck('bundle_id')->all());
    }

    /** 按 ID 取启用、在有效期内、商品数量够的套餐；立减金额大的在前，一样大的按排序和 ID */
    private function load(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $now = now();
        $bundles = Bundle::query()->whereIn('id', $ids)->where('status', 1)
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now))
            ->orderByDesc('reduce_amount')->orderBy('sort')->orderBy('id')->get(['id', 'name', 'reduce_amount']);
        $items = DB::table('bundle_item')->whereIn('bundle_id', $bundles->pluck('id')->all())->orderBy('sort')->orderBy('id')->get(['bundle_id', 'sku_id'])->groupBy('bundle_id');

        $out = [];
        foreach ($bundles as $b) {
            $skuIds = ($items->get($b->id) ?? collect())->pluck('sku_id')->map(fn ($v) => (int) $v)->all();
            if (count($skuIds) >= Bundle::MIN_ITEMS) {
                $out[] = ['id' => (int) $b->id, 'name' => (string) $b->name, 'reduce_amount' => (string) $b->reduce_amount, 'sku_ids' => $skuIds];
            }
        }

        return $out;
    }
}
