<?php

namespace Tests\Unit;

use App\Services\Common\ShippingFeeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 运费规则的纯计算部分：首续费、地区规则匹配 */
class ShippingFeeServiceTest extends TestCase
{
    private const RULE = ['first_unit' => '1.000', 'first_fee' => '10.00', 'step_unit' => '1.000', 'step_fee' => '5.00'];

    #[DataProvider('feeCases')]
    public function test_fee_for_rule(array $rule, string $total, string $expected): void
    {
        $this->assertSame($expected, (new ShippingFeeService)->feeForRule($rule, $total));
    }

    public static function feeCases(): array
    {
        $weight = ['first_unit' => '1.000', 'first_fee' => '8.00', 'step_unit' => '0.500', 'step_fee' => '2.50'];

        return [
            '未超过首件只收首费' => [self::RULE, '1.000', '10.00'],
            '不足首件也收首费' => [self::RULE, '0.300', '10.00'],
            '超出部分按续件计' => [self::RULE, '3.000', '20.00'],
            '续重不足一个单位按一个算' => [$weight, '1.200', '10.50'],
            '续重刚好整除' => [$weight, '2.000', '13.00'],
            '续重超出一点进位' => [$weight, '2.001', '15.50'],
            '续费单位为 0 时只收首费' => [['step_unit' => '0.000'] + self::RULE, '9.000', '10.00'],
        ];
    }

    public function test_pick_rule_prefers_province_then_country_then_fallback(): void
    {
        $rules = [
            ['id' => 1, 'countries' => '*', 'provinces' => ''],
            ['id' => 2, 'countries' => 'CN', 'provinces' => ''],
            ['id' => 3, 'countries' => 'CN', 'provinces' => '新疆,西藏'],
            ['id' => 4, 'countries' => 'US,CA', 'provinces' => ''],
        ];
        $svc = new ShippingFeeService;

        $this->assertSame(3, $svc->pickRule($rules, 'CN', '新疆')['id']);
        $this->assertSame(2, $svc->pickRule($rules, 'CN', '广东省')['id']);
        $this->assertSame(4, $svc->pickRule($rules, 'CA', 'Ontario')['id']);
        $this->assertSame(1, $svc->pickRule($rules, 'JP', '')['id']);
    }

    public function test_pick_rule_matches_province_case_insensitively(): void
    {
        $rules = [['id' => 1, 'countries' => 'us', 'provinces' => 'Alaska, Hawaii']];

        $this->assertSame(1, (new ShippingFeeService)->pickRule($rules, 'US', 'hawaii')['id']);
    }

    public function test_pick_rule_returns_null_when_region_not_covered(): void
    {
        $svc = new ShippingFeeService;

        $this->assertNull($svc->pickRule([['countries' => 'CN', 'provinces' => '']], 'US', ''));
        // 只配了个别省份、没有整国规则：其他省份不配送
        $this->assertNull($svc->pickRule([['countries' => 'CN', 'provinces' => '新疆']], 'CN', '广东省'));
        $this->assertNull($svc->pickRule([], 'CN', ''));
    }
}
