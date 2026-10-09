<?php

namespace App\Support;

/** 金额计算：全部用字符串 + bcmath，避免浮点误差；舍入为四舍五入（远离 0） */
final class Money
{
    public static function round(string $v, int $scale = 2): string
    {
        $half = '0.' . str_repeat('0', $scale) . '5';
        $neg = str_starts_with($v, '-');
        $abs = $neg ? substr($v, 1) : $v;
        $r = bcadd($abs, $half, $scale);

        return ($neg && bccomp($r, '0', $scale) !== 0 ? '-' : '') . $r;
    }

    /** 基准币种金额 → 目标币种（rate=基准→目标），按目标币种小数位舍入后统一存两位小数 */
    public static function fromBase(string $base, string $rate, int $decimals): string
    {
        return bcadd(self::round(bcmul($base, $rate, 8), $decimals), '0', 2);
    }

    /** 目标币种金额 → 基准币种 */
    public static function toBase(string $amount, string $rate): string
    {
        if (bccomp($rate, '0', 8) <= 0) {
            return '0.00';
        }

        return self::round(bcdiv($amount, $rate, 8), 2);
    }

    public static function mul(string $a, int|string $qty): string
    {
        return bcmul($a, (string) $qty, 2);
    }
}
