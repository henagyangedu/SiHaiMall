<?php

namespace App\Support;

use App\Support\Exceptions\BusinessException;

/** 渠道金额换算：商城统一用「两位小数的字符串」，渠道可能要求最小货币单位（分）或零小数币种 */
final class PayAmount
{
    /** 无小数位币种（Stripe 零小数币种；PayPal 的 JPY/HUF/TWD 同样不接受小数） */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF', 'HUF', 'TWD'];

    /** 三位小数币种：渠道处理规则特殊，暂不支持，避免金额错 10 倍 */
    private const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    public static function decimals(string $currency): int
    {
        $c = strtoupper($currency);
        if (in_array($c, self::THREE_DECIMAL, true)) {
            throw new BusinessException("暂不支持 {$c} 币种的在线支付");
        }

        return in_array($c, self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** "12.34" → 1234（USD）；"1500.00" → 1500（JPY） */
    public static function toMinor(string $amount, string $currency): string
    {
        return self::decimals($currency) === 0
            ? Money::round($amount, 0)
            : Money::round(bcmul($amount, '100', 4), 0);
    }

    /** 1234 → "12.34"；JPY 1500 → "1500.00"（统一两位小数存库） */
    public static function fromMinor(string|int $minor, string $currency): string
    {
        $m = (string) $minor;

        return self::decimals($currency) === 0 ? bcadd($m, '0', 2) : bcdiv($m, '100', 2);
    }

    /** 渠道要求的金额字符串：普通币种两位小数，零小数币种为整数 */
    public static function format(string $amount, string $currency): string
    {
        return Money::round($amount, self::decimals($currency));
    }
}
