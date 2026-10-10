<?php

namespace Tests\Feature;

use App\Models\Order;
use Tests\FeatureTestCase;

/** 买家接口里的时间按请求头 X-Timezone 换算；后台和没带请求头的请求不变 */
class BuyerTimezoneTest extends FeatureTestCase
{
    public function test_buyer_times_follow_the_timezone_header(): void
    {
        $this->assertSame('Asia/Shanghai', config('app.timezone'));
        [$member, $token] = $this->makeMember();
        $order = $this->placeOrder($token, $this->makeSku('100.00', 10), 1, $this->makeAddress((int) $member->id)->id);
        // 北京时间 1 月 15 日 08:30（纽约是冬令时，差 13 小时）和 7 月 15 日 08:30（夏令时，差 12 小时）
        Order::query()->whereKey($order->id)->update(['created_at' => '2026-01-15 08:30:00', 'expire_at' => '2026-07-15 08:30:00']);
        $get = fn (array $headers = []) => $this->api('GET', "/orders/{$order->order_no}", [], $token, $headers);

        // 不带请求头、时区认不出来、和应用时区相同：原样
        $this->assertSame('2026-01-15 08:30:00', $get()->json('data.created_at'));
        $this->assertSame('2026-01-15 08:30:00', $get(['X-Timezone' => 'Mars/Olympus'])->json('data.created_at'));
        $this->assertSame('2026-01-15 08:30:00', $get(['X-Timezone' => '<script>'])->json('data.created_at'));
        $this->assertSame('2026-01-15 08:30:00', $get(['X-Timezone' => 'Asia/Shanghai'])->json('data.created_at'));

        // 纽约：跨到前一天，夏令时也算对
        $ny = $get(['X-Timezone' => 'America/New_York']);
        $this->assertSame(['2026-01-14 19:30:00', '2026-07-14 20:30:00'], [$ny->json('data.created_at'), $ny->json('data.expire_at')]);
        // 偏移写法（拿不到时区名称的设备）
        $this->assertSame('2026-01-15 01:30:00', $get(['X-Timezone' => '+01:00'])->json('data.created_at'));
        $this->assertSame('2026-01-14 14:30:00', $get(['X-Timezone' => '-10:00'])->json('data.created_at'));
        // 列表里嵌套的时间也换
        $this->assertSame('2026-01-15 00:30:00', $this->api('GET', '/orders', [], $token, ['X-Timezone' => 'Europe/London'])->json('data.list.0.created_at'));

        // 其他内容不动：订单号、金额；只有日期的字段（生日）不换
        $this->assertSame([$order->order_no, (string) $order->pay_amount], [$ny->json('data.order_no'), (string) $ny->json('data.pay_amount')]);
        $this->api('PUT', '/member/profile', ['nickname' => '时区', 'birthday' => '1990-01-01'], $token)->assertJsonPath('code', 0);
        $this->assertSame('1990-01-01', $this->api('GET', '/member/profile', [], $token, ['X-Timezone' => 'America/New_York'])->json('data.birthday'));

        // 后台不受影响
        $admin = $this->adminApi('GET', "/order/list/{$order->id}", [], ['X-Timezone' => 'America/New_York']);
        $this->assertSame('2026-01-15 08:30:00', $admin->json('data.created_at'));
    }
}
