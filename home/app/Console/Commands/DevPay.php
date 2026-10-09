<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Common\PayService;
use Illuminate\Console\Command;

/** 开发联调：没有 C 端时发起支付、模拟「买家返回后确认」。生产环境禁用。 */
class DevPay extends Command
{
    protected $signature = 'mall:dev-pay {order_no : 订单号} {channel : stripe / paypal} {--confirm : 不发起支付，而是对该订单最近一笔支付单做确认入账}';

    protected $description = '【仅开发环境】为订单发起支付并输出收银台地址，或确认入账';

    public function handle(PayService $pay): int
    {
        if (app()->isProduction()) {
            $this->error('生产环境禁用此命令');

            return self::FAILURE;
        }
        $order = Order::query()->where('order_no', $this->argument('order_no'))->first();
        if (!$order) {
            $this->error('订单不存在');

            return self::FAILURE;
        }

        if ($this->option('confirm')) {
            $tradeNo = \App\Models\PayTrade::query()->where('order_id', $order->id)->where('channel', $this->argument('channel'))->orderByDesc('id')->value('trade_no');
            if (!$tradeNo) {
                $this->error('该订单还没有该渠道的支付单');

                return self::FAILURE;
            }
            $r = $pay->confirm($tradeNo);
            $this->info("支付单 {$tradeNo} 状态：{$r['status']}");

            return self::SUCCESS;
        }

        $r = $pay->start((int) $order->id, 0, (string) $this->argument('channel'), 'h5');
        $this->info("支付单：{$r['trade_no']}");
        $this->line("收银台地址（在浏览器打开并用沙箱/测试账号付款）：\n{$r['redirect_url']}");

        return self::SUCCESS;
    }
}
