<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Common\DistributionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 分销的纯计算：邀请码、计佣基数、佣金、退款后的佣金（不访问数据库） */
class DistributionRulesTest extends TestCase
{
    public function test_invite_code_round_trip_and_tamper_detection(): void
    {
        foreach ([1, 7, 36, 1295, 1296, 987654321] as $id) {
            $code = DistributionService::inviteCode($id);
            $this->assertMatchesRegularExpression('/^[0-9A-Z]{3,}$/', $code);
            $this->assertSame($id, DistributionService::decodeInviteCode($code));
            $this->assertSame($id, DistributionService::decodeInviteCode(' ' . strtolower($code) . ' '), '大小写和首尾空格不影响');
        }

        // 改掉校验位、截短、非法字符都无效
        $code = DistributionService::inviteCode(1295);
        $this->assertNull(DistributionService::decodeInviteCode(substr($code, 0, -1) . ($code[-1] === 'A' ? 'B' : 'A')));
        $this->assertNull(DistributionService::decodeInviteCode('ZZ'));
        $this->assertNull(DistributionService::decodeInviteCode('AB-CD'));
        $this->assertNull(DistributionService::decodeInviteCode(''));
    }

    public function test_commission_base_excludes_shipping_discount_and_points_and_converts_to_base_currency(): void
    {
        // 基准币种订单：300 - 20 - 30 = 250，运费不算
        $cny = new Order(['goods_amount' => '300.00', 'discount_amount' => '20.00', 'points_amount' => '30.00', 'shipping_fee' => '15.00', 'rate' => '1.00000000']);
        $this->assertSame('250.00', DistributionService::commissionBase($cny));

        // USD 订单：39.98 USD ÷ 0.14 = 285.57 基准币种
        $usd = new Order(['goods_amount' => '39.98', 'discount_amount' => '0.00', 'points_amount' => '0.00', 'shipping_fee' => '21.00', 'rate' => '0.14000000']);
        $this->assertSame('285.57', DistributionService::commissionBase($usd));

        // 折扣加积分把商品款抵完：不计佣
        $zero = new Order(['goods_amount' => '50.00', 'discount_amount' => '20.00', 'points_amount' => '30.00', 'shipping_fee' => '10.00', 'rate' => '1.00000000']);
        $this->assertSame('0.00', DistributionService::commissionBase($zero));
    }

    #[DataProvider('amountCases')]
    public function test_commission_amount(string $base, string $rate, string $expected): void
    {
        $this->assertSame($expected, DistributionService::commissionAmount($base, $rate));
    }

    public static function amountCases(): array
    {
        return [
            '10%' => ['299.70', '10.00', '29.97'],
            '5% 四舍五入' => ['299.70', '5.00', '14.99'],
            '小数比例' => ['100.00', '2.50', '2.50'],
            '比例为 0' => ['100.00', '0.00', '0.00'],
        ];
    }

    #[DataProvider('refundCases')]
    public function test_commission_after_refund(string $original, string $goodsPaid, string $refunded, string $expected): void
    {
        $this->assertSame($expected, DistributionService::afterRefund($original, $goodsPaid, $refunded));
    }

    public static function refundCases(): array
    {
        return [
            '没有退款' => ['29.97', '299.70', '0.00', '29.97'],
            '退三分之一' => ['29.97', '299.70', '99.90', '19.98'],
            '商品款全退' => ['29.97', '299.70', '299.70', '0.00'],
            '连运费一起退（超过商品款）' => ['29.97', '299.70', '319.70', '0.00'],
            '商品款为 0' => ['29.97', '0.00', '0.00', '0.00'],
        ];
    }
}
