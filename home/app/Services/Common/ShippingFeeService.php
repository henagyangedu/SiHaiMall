<?php

namespace App\Services\Common;

use App\Models\ShippingTemplate;
use App\Support\Exceptions\BusinessException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * 运费计算（结算预览与下单共用，保证两处结果一致）。
 *
 * 规则：
 * - 商品未选运费模板（shipping_template_id=0）或模板为“默认包邮”：该商品免运费；虚拟商品不计运费。
 * - 同一模板的商品合并计费：按件数 / 总重量 / 总体积套用「首费 + 续费」，续费不足一个续费单位按一个算。
 * - 地区规则匹配优先级：指定省份 > 指定国家 > 其他所有（*）。都不匹配表示该地区不配送，直接拒绝。
 * - 满额包邮按该模板下商品金额（折回基准币种）判断。
 * - 多个模板的运费相加。模板金额均为基准币种，汇总后再折算成下单币种。
 */
class ShippingFeeService
{
    /**
     * @param array<int,array{qty:int,amount:string}> $lines sku_id => 数量、行金额（下单币种）
     * @param array<string,mixed> $address 至少含 country_code，可含 province
     * @param string $rate 基准币种 → 下单币种汇率
     * @return string 下单币种运费
     */
    public function calc(array $lines, array $address, string $rate, int $decimals): string
    {
        if ($lines === []) {
            return '0.00';
        }

        $skus = DB::table('goods_sku as s')->join('goods as g', 'g.id', '=', 's.goods_id')
            ->whereIn('s.id', array_keys($lines))
            ->get(['s.id', 's.weight', 's.volume', 'g.shipping_template_id', 'g.is_virtual']);

        // ---- 按模板分组汇总件数、重量、体积、金额
        $groups = [];
        foreach ($skus as $s) {
            $tid = (int) $s->shipping_template_id;
            if ($tid === 0 || (int) $s->is_virtual === 1) {
                continue;
            }
            $qty = (string) (int) $lines[$s->id]['qty'];
            $g = $groups[$tid] ?? ['piece' => '0', 'weight' => '0', 'volume' => '0', 'amount' => '0.00'];
            $groups[$tid] = [
                'piece'  => bcadd($g['piece'], $qty, 3),
                'weight' => bcadd($g['weight'], bcmul((string) $s->weight, $qty, 3), 3),
                'volume' => bcadd($g['volume'], bcmul((string) $s->volume, $qty, 3), 3),
                'amount' => bcadd($g['amount'], (string) $lines[$s->id]['amount'], 2),
            ];
        }
        if ($groups === []) {
            return '0.00';
        }

        $templates = ShippingTemplate::query()->whereIn('id', array_keys($groups))->get()->keyBy('id');
        $regions = DB::table('shipping_template_region')->whereIn('template_id', array_keys($groups))->orderBy('id')->get()->groupBy('template_id');
        $country = strtoupper(trim((string) ($address['country_code'] ?? '')));
        $province = trim((string) ($address['province'] ?? ''));

        $baseFee = '0.00';
        foreach ($groups as $tid => $g) {
            $tpl = $templates->get($tid);
            // 模板已被删除按包邮处理（后台禁止删除使用中的模板，这里只是兜底）
            if (!$tpl || (int) $tpl->is_free_default === 1) {
                continue;
            }
            $rule = $this->pickRule(($regions->get($tid) ?? collect())->map(fn ($r) => (array) $r)->all(), $country, $province);
            if ($rule === null) {
                throw new BusinessException('部分商品不支持配送到所选地区，请更换收货地址');
            }
            $free = (string) $rule['free_amount'];
            if (bccomp($free, '0', 2) > 0 && bccomp(Money::toBase($g['amount'], $rate), $free, 2) >= 0) {
                continue;
            }
            $total = match ((int) $tpl->charge_type) {
                ShippingTemplate::CHARGE_WEIGHT => $g['weight'],
                ShippingTemplate::CHARGE_VOLUME => $g['volume'],
                default => $g['piece'],
            };
            $baseFee = bcadd($baseFee, $this->feeForRule($rule, $total), 2);
        }

        return Money::fromBase($baseFee, $rate, $decimals);
    }

    /**
     * 选出适用的地区规则；没有适用规则返回 null（不配送）。
     * @param array<int,array<string,mixed>> $rules 每项含 countries（逗号分隔国家码或 *）、provinces（逗号分隔，可空）
     * @return array<string,mixed>|null
     */
    public function pickRule(array $rules, string $country, string $province): ?array
    {
        $byCountry = null;
        $fallback = null;
        foreach ($rules as $r) {
            $countries = self::split((string) ($r['countries'] ?? ''), true);
            if (in_array('*', $countries, true)) {
                $fallback ??= $r;
                continue;
            }
            if ($country === '' || !in_array($country, $countries, true)) {
                continue;
            }
            $provinces = self::split((string) ($r['provinces'] ?? ''), false);
            if ($provinces === []) {
                $byCountry ??= $r;
            } elseif ($province !== '' && in_array(mb_strtolower($province), array_map('mb_strtolower', $provinces), true)) {
                return $r;
            }
        }

        return $byCountry ?? $fallback;
    }

    /**
     * 按一条规则计算运费（基准币种）。
     * @param array<string,mixed> $rule first_unit、first_fee、step_unit、step_fee
     * @param string $total 件数 / 重量 / 体积合计
     */
    public function feeForRule(array $rule, string $total): string
    {
        $fee = bcadd((string) $rule['first_fee'], '0', 2);
        $extra = bcsub($total, (string) $rule['first_unit'], 3);
        $step = (string) $rule['step_unit'];
        if (bccomp($extra, '0', 3) <= 0 || bccomp($step, '0', 3) <= 0) {
            return $fee;
        }
        $steps = bcdiv($extra, $step, 0);
        if (bccomp(bcmod($extra, $step, 3), '0', 3) !== 0) {
            $steps = bcadd($steps, '1', 0); // 不足一个续费单位按一个算
        }

        return bcadd($fee, bcmul((string) $rule['step_fee'], $steps, 2), 2);
    }

    /** @return string[] */
    public static function split(string $v, bool $upper): array
    {
        $parts = array_filter(array_map('trim', explode(',', $v)), fn ($x) => $x !== '');

        return array_values(array_unique($upper ? array_map('strtoupper', $parts) : $parts));
    }
}
