<?php

namespace App\Services\Common;

use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * SKU 单价的唯一计算入口：目录、购物车、结算预览、下单（OrderCreateService）都调用 unitPrices，保证规则一致。
 * 下单时把结果固化为订单快照。
 */
class PriceService
{
    public function __construct(private LocaleService $locale) {}

    /** @return array{code:string,symbol:string,rate:string,decimals:int} */
    public function currency(string $code): array
    {
        foreach ($this->locale->currencies() as $c) {
            if ($c['code'] === $code) {
                return ['code' => $c['code'], 'symbol' => $c['symbol'], 'rate' => $c['is_base'] === 1 ? '1.00000000' : $c['rate'], 'decimals' => $c['decimals']];
            }
        }
        $base = $this->locale->baseCurrency();
        foreach ($this->locale->currencies() as $c) {
            if ($c['code'] === $base) {
                return ['code' => $c['code'], 'symbol' => $c['symbol'], 'rate' => '1.00000000', 'decimals' => $c['decimals']];
            }
        }

        return ['code' => $code, 'symbol' => '', 'rate' => '1.00000000', 'decimals' => 2];
    }

    /**
     * 取价顺序：销售区域价（市级区域没填则用整省区域的）→ 币种单独定价 → 基准价 × 汇率。
     * 区域价按区域的定价币种填写，买家用其他币种结算时按汇率换算。
     *
     * @param array<int,string> $basePrices sku_id => 基准币种价
     * @param array{id:int,name:string,currency:string,fallback?:array}|null $region SaleRegionService::match 的结果，null 表示标准价
     * @return array<int,string> sku_id => 目标币种单价
     */
    public function unitPrices(array $basePrices, string $currency, ?array $region = null): array
    {
        if ($basePrices === []) {
            return [];
        }
        $cur = $this->currency($currency);
        $skuIds = array_keys($basePrices);
        $own = DB::table('goods_price')->whereIn('sku_id', $skuIds)->where('currency', $cur['code'])->pluck('price', 'sku_id');

        // 区域价逐级回退：市级区域 → 整省区域（$region['fallback']）；定价币种未启用的区域无法换算，跳过
        $tiers = [];
        for ($r = $region; $r; $r = $r['fallback'] ?? null) {
            $rate = $this->enabledRate($r['currency']);
            if ($rate !== null) {
                $tiers[] = [
                    'currency' => $r['currency'], 'rate' => $rate,
                    'prices' => DB::table('goods_region_price')->whereIn('sku_id', $skuIds)->where('region_id', $r['id'])->pluck('price', 'sku_id')->all(),
                ];
            }
        }

        $out = [];
        foreach ($basePrices as $skuId => $base) {
            foreach ($tiers as $t) {
                if (isset($t['prices'][$skuId])) {
                    $out[$skuId] = $t['currency'] === $cur['code']
                        ? bcadd((string) $t['prices'][$skuId], '0', 2)
                        : Money::convert((string) $t['prices'][$skuId], $t['rate'], $cur['rate'], $cur['decimals']);
                    continue 2;
                }
            }
            $out[$skuId] = isset($own[$skuId])
                ? bcadd((string) $own[$skuId], '0', 2)
                : Money::fromBase((string) $base, $cur['rate'], $cur['decimals']);
        }

        return $out;
    }

    /** 启用币种的汇率（基准→该币种）；币种未启用返回 null，此时区域价无法换算，按标准价处理 */
    private function enabledRate(string $code): ?string
    {
        foreach ($this->locale->currencies() as $c) {
            if ($c['code'] === $code) {
                return $c['is_base'] === 1 ? '1.00000000' : (string) $c['rate'];
            }
        }

        return null;
    }

    public function fmt(string $amount, string $currency): array
    {
        return ['amount' => $amount, 'currency' => $currency, 'symbol' => $this->currency($currency)['symbol']];
    }
}
