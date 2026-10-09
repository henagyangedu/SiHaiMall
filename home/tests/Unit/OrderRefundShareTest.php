<?php

namespace Tests\Unit;

use App\Services\Common\OrderRefundService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 单品售后的可退上限：按商品金额占比分摊实付（不含运费），不超过订单剩余可退金额 */
class OrderRefundShareTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_share(string $price, int $qty, string $goods, string $pay, string $shipping, string $remain, string $expected): void
    {
        $this->assertSame($expected, OrderRefundService::share($price, $qty, $goods, $pay, $shipping, $remain));
    }

    public static function cases(): array
    {
        return [
            '无优惠：退 1 件等于单价，运费不退' => ['79.90', 1, '239.70', '259.70', '20.00', '259.70', '79.90'],
            '无优惠：全部件数等于商品金额' => ['79.90', 3, '239.70', '259.70', '20.00', '259.70', '239.70'],
            '有优惠：按占比分摊优惠后的实付' => ['50.00', 2, '200.00', '190.00', '10.00', '190.00', '90.00'],
            '分摊结果四舍五入到分' => ['33.33', 1, '99.99', '89.99', '0.00', '89.99', '30.00'],
            '不超过订单剩余可退金额' => ['50.00', 2, '200.00', '190.00', '10.00', '30.00', '30.00'],
            '剩余可退为 0' => ['50.00', 1, '200.00', '190.00', '10.00', '0.00', '0.00'],
            '实付不超过运费时商品部分不可退' => ['50.00', 1, '50.00', '10.00', '10.00', '10.00', '0.00'],
            '商品金额为 0' => ['0.00', 1, '0.00', '10.00', '10.00', '10.00', '0.00'],
        ];
    }
}
