<?php

namespace App\Services\Common;

use App\Models\Order;
use App\Models\TaxRate;
use App\Support\Money;

/**
 * 税费：按收货地址匹配税率，算出一张订单的税（结算预览、下单、后台改价共用）。
 *
 * - 匹配：省 / 州 > 整个国家；没有匹配的规则、没有收货地址（虚拟商品）、总开关没开，都不收税。
 * - 计税基数：商品 − 套餐优惠 − 会员折扣 − 优惠券 − 积分抵扣（调用方传进来的 $net）；规则勾了“运费也计税”时运费另算一份。
 * - 价外税（included=0）：税另加，计入应付。价内税（included=1）：标价已含税，应付不变，只是算出其中含多少税。
 * - 一张订单只有一个税率；商品部分和运费部分分别按币种的小数位四舍五入后相加。
 */
class TaxService
{
    public function __construct(private ConfigService $config) {}

    public function enabled(): bool
    {
        return (int) $this->config->value('trade', 'tax_enabled') === 1;
    }

    /** 收货地址适用的税率规则：省 / 州（不分大小写）优先，其次整个国家 */
    public function match(array $address): ?TaxRate
    {
        $country = strtoupper(trim((string) ($address['country_code'] ?? '')));
        if ($country === '') {
            return null;
        }
        $province = mb_strtolower(trim((string) ($address['province'] ?? '')));
        $rules = TaxRate::query()->where('country_code', $country)->where('status', 1)->get();
        $exact = $province === '' ? null : $rules->first(fn (TaxRate $r) => mb_strtolower((string) $r->province) === $province);

        return $exact ?? $rules->first(fn (TaxRate $r) => (string) $r->province === '');
    }

    /**
     * 一张订单的税。
     * @param array<string,mixed>|null $address 收货地址（country_code、province）；无需发货的订单传 null
     * @param string $net 商品扣掉各种优惠之后的金额（下单币种）
     * @return array{amount:string,shipping:string,rate:string,name:string,included:int,extra:string} extra 是要加到应付里的金额（价内税为 0）
     */
    public function quote(?array $address, string $net, string $shipping, int $decimals): array
    {
        $rule = $address && $this->enabled() ? $this->match($address) : null;
        if (!$rule) {
            return ['amount' => '0.00', 'shipping' => '0.00', 'rate' => '0.000', 'name' => '', 'included' => 0, 'extra' => '0.00'];
        }

        return ['name' => (string) $rule->name] + self::calc($net, $shipping, (string) $rule->rate, (int) $rule->included === 1, (int) $rule->shipping_taxable === 1, $decimals);
    }

    /**
     * 纯计算。
     * @return array{amount:string,shipping:string,rate:string,included:int,extra:string}
     */
    public static function calc(string $net, string $shipping, string $rate, bool $included, bool $shippingTaxable, int $decimals): array
    {
        $goodsTax = self::part($net, $rate, $included, $decimals);
        $shipTax = $shippingTaxable ? self::part($shipping, $rate, $included, $decimals) : '0.00';
        $amount = bcadd($goodsTax, $shipTax, 2);

        return ['amount' => $amount, 'shipping' => $shipTax, 'rate' => bcadd($rate, '0', 3), 'included' => $included ? 1 : 0, 'extra' => $included ? '0.00' : $amount];
    }

    /** 一笔金额对应的税：价外 = 金额 × 税率；价内 = 金额 × 税率 ÷ (100 + 税率) */
    public static function part(string $amount, string $rate, bool $included, int $decimals): string
    {
        if (bccomp($amount, '0', 2) <= 0 || bccomp($rate, '0', 3) <= 0) {
            return '0.00';
        }
        $tax = $included
            ? bcdiv(bcmul($amount, $rate, 8), bcadd('100', $rate, 3), 8)
            : bcdiv(bcmul($amount, $rate, 8), '100', 8);

        return bcadd(Money::round($tax, $decimals), '0', 2);
    }

    /** 这张订单有没有另加的税（价外税） */
    public static function hasExtra(Order $order): bool
    {
        return (int) $order->tax_included === 0 && bccomp((string) $order->tax_amount, '0', 2) > 0;
    }

    /** 买家为运费付了多少：运费，加上运费上另加的税。退单件商品时这部分不退 */
    public static function shippingPaid(Order $order): string
    {
        return (int) $order->tax_included === 0
            ? bcadd((string) $order->shipping_fee, (string) $order->tax_shipping, 2)
            : (string) $order->shipping_fee;
    }

    /** 买家为商品付了多少：实付 − 运费那部分（价外税时含商品的税）。退款分摊、佣金按退款比例缩减都以它为分母 */
    public static function goodsPaid(Order $order): string
    {
        return bcsub((string) $order->pay_amount, self::shippingPaid($order), 2);
    }

    /** 把一笔“含另加的税”的商品款还原成不含税的金额（消费得积分用：税不算消费）。没有价外税时原样返回 */
    public static function exTax(Order $order, string $amount): string
    {
        if (!self::hasExtra($order) || bccomp((string) $order->tax_rate, '0', 3) <= 0) {
            return $amount;
        }

        return Money::round(bcdiv(bcmul($amount, '100', 8), bcadd('100', (string) $order->tax_rate, 3), 8), 2);
    }

    /**
     * 后台改价后重算：应付改成 $newPay，税率不变，倒推出商品净额和税。
     * @return array{net:string,tax_amount:string}|null 改后金额连运费都不够时返回 null
     */
    public static function requote(Order $order, string $newPay): ?array
    {
        $rate = (string) $order->tax_rate;
        $included = (int) $order->tax_included === 1;
        $goodsPart = bcsub($newPay, self::shippingPaid($order), 2);
        if (bccomp($goodsPart, '0', 2) <= 0) {
            return null;
        }
        if ($included) {
            // 价内税：商品净额就是这部分钱，其中含的税重算
            return ['net' => $goodsPart, 'tax_amount' => bcadd(self::part($goodsPart, $rate, true, 2), (string) $order->tax_shipping, 2)];
        }
        // 价外税：这部分钱 = 净额 + 净额的税，倒推净额，差额就是税（保证恒等式分毫不差）
        $net = Money::round(bcdiv(bcmul($goodsPart, '100', 8), bcadd('100', $rate, 3), 8), 2);

        return ['net' => $net, 'tax_amount' => bcadd(bcsub($goodsPart, $net, 2), (string) $order->tax_shipping, 2)];
    }
}
