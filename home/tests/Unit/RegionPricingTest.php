<?php

namespace Tests\Unit;

use App\Services\Common\SaleRegionService;
use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 区域定价的纯计算部分：收货地址 → 销售区域的匹配，区域价的币种换算 */
class RegionPricingTest extends TestCase
{
    private const AREAS = [
        ['region_id' => 1, 'country_code' => 'CN', 'province' => '广东省', 'city' => ''],
        ['region_id' => 2, 'country_code' => 'CN', 'province' => '广东省', 'city' => '深圳市'],
        ['region_id' => 3, 'country_code' => 'CN', 'province' => '浙江省', 'city' => '杭州市'],
        ['region_id' => 4, 'country_code' => 'US', 'province' => '', 'city' => ''],
        ['region_id' => 4, 'country_code' => 'CA', 'province' => '', 'city' => ''],
    ];

    #[DataProvider('addressCases')]
    public function test_pick_region(string $country, string $province, string $city, ?int $expected): void
    {
        $this->assertSame($expected, SaleRegionService::pick(self::AREAS, $country, $province, $city));
    }

    public static function addressCases(): array
    {
        return [
            '市级规则优先于整省' => ['CN', '广东省', '深圳市', 2],
            '同省其他市走整省规则' => ['CN', '广东省', '广州市', 1],
            '只配了个别市的省，其他市不命中' => ['CN', '浙江省', '宁波市', null],
            '只配了个别市的省，该市命中' => ['CN', '浙江省', '杭州市', 3],
            '未配置的省不命中' => ['CN', '北京市', '北京市', null],
            '省名不规范不命中' => ['CN', '广东', '深圳市', null],
            '境外按国家，忽略省市' => ['US', 'Texas', 'Austin', 4],
            '国家码不区分大小写' => ['ca', '', '', 4],
            '未配置的国家不命中' => ['JP', '', '', null],
            '没有国家码不命中' => ['', '广东省', '深圳市', null],
        ];
    }

    #[DataProvider('convertCases')]
    public function test_convert_between_currencies(string $amount, string $fromRate, string $toRate, int $decimals, string $expected): void
    {
        $this->assertSame($expected, Money::convert($amount, $fromRate, $toRate, $decimals));
    }

    public static function convertCases(): array
    {
        return [
            'USD 区域价换成基准币种' => ['19.99', '0.14000000', '1.00000000', 2, '142.79'],
            '基准币种区域价换成 USD' => ['79.90', '1.00000000', '0.14000000', 2, '11.19'],
            '同汇率原样返回' => ['19.99', '0.14000000', '0.14000000', 2, '19.99'],
            '目标币种无小数位（如 JPY）' => ['19.99', '0.14000000', '21.50000000', 0, '3070.00'],
            '来源汇率无效返回 0' => ['19.99', '0', '1.00000000', 2, '0.00'],
        ];
    }
}
