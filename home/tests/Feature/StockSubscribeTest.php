<?php

namespace Tests\Feature;

use App\Mail\TemplateMail;
use App\Services\Common\StockSubscribeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\FeatureTestCase;

/** 到货提醒：缺货的规格可以订阅，补货后由定时任务通知一次 */
class StockSubscribeTest extends FeatureTestCase
{
    private function goodsId(int $sku): int
    {
        return (int) DB::table('goods_sku')->where('id', $sku)->value('goods_id');
    }

    private function setStock(int $sku, int $stock): void
    {
        DB::table('goods_sku')->where('id', $sku)->update(['stock' => $stock]);
    }

    private function enableScene(): void
    {
        $this->adminApi('PUT', '/message/goods_restock', ['is_enabled' => 1])->assertJsonPath('code', 0);
    }

    public function test_only_sold_out_skus_can_be_subscribed_and_it_is_idempotent(): void
    {
        [, $token] = $this->makeMember();
        $inStock = $this->makeSku('100.00', 5);
        $soldOut = $this->makeSku('100.00', 0);
        $goods = $this->goodsId($soldOut);

        $this->api('POST', "/stock-subscribes/{$soldOut}")->assertJsonPath('code', 401);
        $this->api('POST', "/stock-subscribes/{$inStock}", [], $token)->assertJsonPath('code', 1)->assertJsonPath('msg', '该商品有货，可以直接购买');
        $this->api('POST', '/stock-subscribes/999999', [], $token)->assertJsonPath('code', 1);

        // 没登录、没订阅：详情里是空的
        $this->api('GET', "/goods/{$goods}")->assertJsonPath('data.restock_sku_ids', []);
        $this->api('GET', "/goods/{$goods}", [], $token)->assertJsonPath('data.restock_sku_ids', []);

        $this->api('POST', "/stock-subscribes/{$soldOut}", [], $token)->assertJsonPath('code', 0)->assertJsonPath('msg', '到货后会通知你');
        $this->api('POST', "/stock-subscribes/{$soldOut}", [], $token)->assertJsonPath('code', 0);
        $this->assertSame(1, DB::table('stock_subscribe')->count());
        $this->api('GET', "/goods/{$goods}", [], $token)->assertJsonPath('data.restock_sku_ids', [$soldOut]);

        $list = $this->api('GET', '/stock-subscribes', [], $token)->json('data');
        $this->assertSame(1, $list['total']);
        $this->assertSame([$soldOut, $goods, 'waiting', false, false], [$list['list'][0]['sku_id'], $list['list'][0]['goods_id'], $list['list'][0]['status'], $list['list'][0]['in_stock'], $list['list'][0]['invalid']]);
        $this->assertNotSame('', $list['list'][0]['title']);

        // 取消两次都算成功
        $this->api('DELETE', "/stock-subscribes/{$soldOut}", [], $token)->assertJsonPath('code', 0);
        $this->api('DELETE', "/stock-subscribes/{$soldOut}", [], $token)->assertJsonPath('code', 0);
        $this->assertSame(0, DB::table('stock_subscribe')->count());

        // 下架的商品不能订阅
        DB::table('goods')->where('id', $goods)->update(['is_on_sale' => 0]);
        $this->api('POST', "/stock-subscribes/{$soldOut}", [], $token)->assertJsonPath('code', 1);

        // 英文买家看到英文提示
        $this->api('POST', "/stock-subscribes/{$inStock}", [], $token, ['X-Lang' => 'en'])->assertJsonPath('msg', 'This item is in stock. You can order it now.');
    }

    public function test_restocked_subscribers_are_notified_exactly_once(): void
    {
        Mail::fake();
        $this->enableScene();
        [$a, $tokenA] = $this->makeMember();
        [$b, $tokenB] = $this->makeMember(['email' => '']);   // 没有邮箱：收不到邮件，但列表里能看到已到货
        $sku = $this->makeSku('100.00', 0);
        $other = $this->makeSku('100.00', 0);
        $service = app(StockSubscribeService::class);

        $this->api('POST', "/stock-subscribes/{$sku}", [], $tokenA)->assertJsonPath('code', 0);
        $this->api('POST', "/stock-subscribes/{$sku}", [], $tokenB)->assertJsonPath('code', 0);
        $this->api('POST', "/stock-subscribes/{$other}", [], $tokenA)->assertJsonPath('code', 0);

        // 还没货：什么都不发
        $this->assertSame([0, 0], $service->notifyRestocked());
        Mail::assertNothingSent();

        // 补货：两个人的订阅都处理了，都收到站内信，只有有邮箱的那位还收到邮件；没补货的那个规格不受影响
        $this->setStock($sku, 3);
        $this->assertSame([2, 2], $service->notifyRestocked());
        $this->assertSame(1, DB::table('member_message')->where('member_id', $b->id)->where('scene', 'goods_restock')->where('link_type', 'goods')->where('link_value', (string) $this->goodsId($sku))->count());
        Mail::assertSent(TemplateMail::class, 1);
        Mail::assertSent(TemplateMail::class, fn (TemplateMail $m) => $m->hasTo($a->email));
        $this->assertSame(1, DB::table('stock_subscribe')->where('sku_id', $other)->where('status', 0)->count());

        // 再跑一遍不会重发
        $this->assertSame([0, 0], $service->notifyRestocked());
        Mail::assertSent(TemplateMail::class, 1);

        // 列表里显示已通知、现在有货；详情里不再算“等待中”
        $row = collect($this->api('GET', '/stock-subscribes', [], $tokenB)->json('data.list'))->firstWhere('sku_id', $sku);
        $this->assertSame(['notified', true], [$row['status'], $row['in_stock']]);
        $this->api('GET', '/goods/'.$this->goodsId($sku), [], $tokenA)->assertJsonPath('data.restock_sku_ids', []);

        // 又卖完了：重新订阅，把原来那条改回等待中；再补货会再通知一次
        $this->setStock($sku, 0);
        $this->api('POST', "/stock-subscribes/{$sku}", [], $tokenA)->assertJsonPath('code', 0);
        $this->assertSame(1, DB::table('stock_subscribe')->where('member_id', $a->id)->where('sku_id', $sku)->count());
        $this->setStock($sku, 1);
        $this->assertSame([1, 1], $service->notifyRestocked());
        Mail::assertSent(TemplateMail::class, 2);

        // 命令能跑
        $this->artisan('mall:restock-notify')->assertSuccessful();
    }

    public function test_scene_disabled_or_goods_off_shelf(): void
    {
        Mail::fake();
        [, $token] = $this->makeMember();
        $sku = $this->makeSku('100.00', 0);
        $offShelf = $this->makeSku('100.00', 0);
        $service = app(StockSubscribeService::class);
        $this->api('POST', "/stock-subscribes/{$sku}", [], $token)->assertJsonPath('code', 0);
        $this->api('POST', "/stock-subscribes/{$offShelf}", [], $token)->assertJsonPath('code', 0);

        // 补了货但商品下架了：不处理，留着等它重新上架；列表里标成失效
        $this->setStock($offShelf, 5);
        DB::table('goods')->where('id', $this->goodsId($offShelf))->update(['is_on_sale' => 0]);
        // 邮件没开、站内信也关掉：照样标成已通知（买家在列表里能看到），只是什么都不发
        $this->adminApi('PUT', '/message/goods_restock', ['site_enabled' => 0])->assertJsonPath('code', 0);
        $this->setStock($sku, 5);
        $this->assertSame([1, 0], $service->notifyRestocked());
        $this->assertSame(0, DB::table('member_message')->count());
        Mail::assertNothingSent();

        $list = collect($this->api('GET', '/stock-subscribes', [], $token)->json('data.list'))->keyBy('sku_id');
        $this->assertSame(['notified', false], [$list[$sku]['status'], $list[$sku]['invalid']]);
        $this->assertSame(['waiting', true, null], [$list[$offShelf]['status'], $list[$offShelf]['invalid'], $list[$offShelf]['price']]);
    }

    public function test_waiting_limit_and_admin_goods_list_count(): void
    {
        [$m, $token] = $this->makeMember();
        [, $token2] = $this->makeMember();
        $sku = $this->makeSku('100.00', 0);
        $goods = $this->goodsId($sku);
        $this->api('POST', "/stock-subscribes/{$sku}", [], $token)->assertJsonPath('code', 0);
        $this->api('POST', "/stock-subscribes/{$sku}", [], $token2)->assertJsonPath('code', 0);

        $row = collect($this->adminApi('GET', '/goods/list?size=50')->json('data.list'))->firstWhere('id', $goods);
        $this->assertSame(2, $row['restock_waiting']);

        // 等待中的最多 50 个；已经订阅的那个再点一次不受影响
        $rows = [];
        foreach (range(1, StockSubscribeService::MAX_WAITING - 1) as $i) {
            $rows[] = ['member_id' => $m->id, 'goods_id' => 900000 + $i, 'sku_id' => 900000 + $i, 'status' => 0, 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('stock_subscribe')->insert($rows);
        $this->api('POST', "/stock-subscribes/{$sku}", [], $token)->assertJsonPath('code', 0);
        $another = $this->makeSku('100.00', 0);
        $this->api('POST', "/stock-subscribes/{$another}", [], $token)->assertJsonPath('code', 1)->assertJsonPath('msg', '到货提醒最多 50 个，请先取消一些');
        $this->api('POST', "/stock-subscribes/{$another}", [], $token, ['X-Lang' => 'en'])->assertJsonPath('msg', 'You can have up to 50 back-in-stock alerts. Please remove some first.');
    }
}
