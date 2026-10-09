<?php

namespace App\Services\Common;

use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * 目录/购物车展示用的 SKU 单价：有该币种单独定价用单独定价，否则 基准价 × 汇率 并按币种小数位四舍五入。
 * 规则必须与 OrderCreateService::create 保持一致；下单时仍以 OrderCreateService 的计算为准并固化快照。
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
     * @param array<int,string> $basePrices sku_id => 基准币种价
     * @return array<int,string> sku_id => 目标币种单价
     */
    public function unitPrices(array $basePrices, string $currency): array
    {
        if ($basePrices === []) {
            return [];
        }
        $cur = $this->currency($currency);
        $own = DB::table('goods_price')->whereIn('sku_id', array_keys($basePrices))->where('currency', $cur['code'])->pluck('price', 'sku_id');

        $out = [];
        foreach ($basePrices as $skuId => $base) {
            $out[$skuId] = isset($own[$skuId])
                ? bcadd((string) $own[$skuId], '0', 2)
                : Money::fromBase((string) $base, $cur['rate'], $cur['decimals']);
        }

        return $out;
    }

    public function fmt(string $amount, string $currency): array
    {
        return ['amount' => $amount, 'currency' => $currency, 'symbol' => $this->currency($currency)['symbol']];
    }
}
