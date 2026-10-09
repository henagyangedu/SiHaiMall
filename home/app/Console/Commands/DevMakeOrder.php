<?php

namespace App\Console\Commands;

use App\Services\Common\OrderCreateService;
use App\Services\Common\OrderPaidService;
use App\Support\OrderStatus;
use Illuminate\Console\Command;

/** 开发联调用：没有 C 端时造测试订单。生产环境禁用。 */
class DevMakeOrder extends Command
{
    protected $signature = 'mall:dev-order {member_id : 会员ID} {sku_id : SKU ID} {qty=1 : 数量} {--currency= : 币种，默认会员偏好} {--paid : 同时标记为已支付（模拟线下收款）}';

    protected $description = '【仅开发环境】创建测试订单';

    public function handle(OrderCreateService $create, OrderPaidService $paid): int
    {
        if (app()->isProduction()) {
            $this->error('生产环境禁用此命令');

            return self::FAILURE;
        }

        $order = $create->create((int) $this->argument('member_id'), [['sku_id' => (int) $this->argument('sku_id'), 'qty' => (int) $this->argument('qty')]], [
            'name' => '测试收件人', 'phone_area' => '86', 'phone' => '13800000000', 'country_code' => 'CN',
            'province' => '广东省', 'city' => '深圳市', 'district' => '南山区', 'address' => '测试路 1 号',
        ], array_filter(['currency' => $this->option('currency'), 'source' => 'h5']));

        $this->info("订单已创建：{$order->order_no}（应付 {$order->pay_amount} {$order->currency}）");

        if ($this->option('paid')) {
            $paid->markPaid((int) $order->id, 'offline', OrderStatus::OP_SYSTEM, 0, '开发命令模拟收款');
            $this->info('已标记为已支付，状态：待发货');
        }

        return self::SUCCESS;
    }
}
