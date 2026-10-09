<?php

namespace Tests\Unit;

use App\Services\Common\OrderPointsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 积分抵扣的纯计算：上限内最多用多少积分、抵多少基准币种金额 */
class OrderPointsCalcTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_calc(int $available, string $base, int $perUnit, int $maxPercent, int $points, string $amount): void
    {
        $this->assertSame(['points' => $points, 'base' => $amount], OrderPointsService::calc($available, $base, $perUnit, $maxPercent));
    }

    public static function cases(): array
    {
        return [
            '积分不够上限：全部用掉' => [5000, '159.80', 100, 50, 5000, '50.00'],
            '积分超过上限：只用到上限' => [20000, '159.80', 100, 50, 7990, '79.90'],
            '零头积分不足一分钱不扣' => [5055, '159.80', 100, 50, 5055, '50.55'],
            '1 积分抵 1 元' => [30, '100.00', 1, 50, 30, '30.00'],
            '兑换比例不整除：金额截断到分，积分向上取整' => [100, '100.00', 3, 50, 100, '33.33'],
            '兑换比例不整除且受上限约束' => [1000, '10.00', 3, 50, 15, '5.00'],
            '积分太少抵不到一分钱' => [2, '100.00', 300, 50, 0, '0.00'],
            '没有积分' => [0, '100.00', 100, 50, 0, '0.00'],
            '金额为 0' => [1000, '0.00', 100, 50, 0, '0.00'],
            '上限百分比超过 100 按 100 算' => [100000, '10.00', 100, 150, 1000, '10.00'],
        ];
    }
}
