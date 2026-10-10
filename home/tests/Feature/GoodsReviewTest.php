<?php

namespace Tests\Feature;

use App\Drivers\DriverManager;
use App\Models\GoodsReview;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Common\OrderRefundService;
use App\Services\Common\UploadService;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 商品评价：什么时候能评、评一次、图片归属、商品页展示和统计、后台隐藏与回复 */
class GoodsReviewTest extends FeatureTestCase
{
    private int $memberId;

    private string $token;

    private int $addressId;

    private int $sku;

    private int $goodsId;

    protected function setUp(): void
    {
        parent::setUp();
        [$m, $this->token] = $this->makeMember(['nickname' => '张小明', 'avatar' => 'https://cdn.test/a.png']);
        $this->memberId = (int) $m->id;
        $this->addressId = (int) $this->makeAddress($m->id)->id;
        $this->sku = $this->makeSku('100.00', 50);
        $this->goodsId = (int) DB::table('goods_sku')->where('id', $this->sku)->value('goods_id');
    }

    /** 下单、付款、发货、确认收货，返回 [订单, 商品行 ID] */
    private function finishedOrder(?string $token = null, ?int $addressId = null, int $qty = 1): array
    {
        $token ??= $this->token;
        $order = $this->pay($this->placeOrder($token, $this->sku, $qty, $addressId ?? $this->addressId));
        $this->adminApi('POST', "/order/ship/{$order->id}", ['express_code' => 'SF', 'express_no' => 'SF1'])->assertJsonPath('code', 0);
        $this->api('POST', "/orders/{$order->order_no}/receive", [], $token)->assertJsonPath('code', 0);

        return [$order->refresh(), (int) DB::table('order_item')->where('order_id', $order->id)->value('id')];
    }

    private function review(Order $order, int $itemId, array $data = [], ?string $token = null)
    {
        return $this->api('POST', "/orders/{$order->order_no}/reviews", $data + ['order_item_id' => $itemId, 'rating' => 5], $token ?? $this->token);
    }

    /** 一张确实由这个会员上传过的图片的地址 */
    private function uploaded(int $memberId, string $name): string
    {
        $path = "member/{$memberId}/review/{$name}";
        DB::table('file')->insert(['driver' => 'local', 'scene' => 'review', 'path' => $path, 'name' => $name, 'ext' => 'png', 'mime' => 'image/png', 'size' => 10, 'created_at' => now()]);

        return app(DriverManager::class)->storage()->url($path);
    }

    public function test_an_item_can_be_reviewed_once_after_the_order_is_completed(): void
    {
        // 付了款、发了货，但还没确认收货：不能评
        $order = $this->pay($this->placeOrder($this->token, $this->sku, 1, $this->addressId));
        $itemId = (int) DB::table('order_item')->where('order_id', $order->id)->value('id');
        $this->review($order, $itemId)->assertJsonPath('code', 1)->assertJsonPath('msg', '订单完成后才能评价');
        $detail = $this->api('GET', "/orders/{$order->order_no}", [], $this->token)->json('data.items.0');
        $this->assertSame([false, false], [$detail['can_review'], $detail['reviewed']]);

        [$order, $itemId] = $this->finishedOrder();
        $this->assertTrue($this->api('GET', "/orders/{$order->order_no}", [], $this->token)->json('data.items.0.can_review'));

        // 评分必须是 1-5；商品行必须是这张订单里的
        $this->assertNotSame(0, $this->review($order, $itemId, ['rating' => 6])->json('code'));
        $this->assertNotSame(0, $this->review($order, $itemId, ['rating' => 0])->json('code'));
        $this->review($order, $itemId + 999)->assertJsonPath('msg', '订单里没有这件商品');

        $this->review($order, $itemId, ['rating' => 4, 'content' => '  质量不错  '])->assertJsonPath('code', 0)->assertJsonPath('msg', '评价成功');
        $row = GoodsReview::query()->firstOrFail();
        $this->assertSame([$this->goodsId, $this->sku, (int) $order->id, $itemId, $this->memberId, 4, '质量不错', 1, 0], [
            (int) $row->goods_id, (int) $row->sku_id, (int) $row->order_id, (int) $row->order_item_id, (int) $row->member_id,
            (int) $row->rating, $row->content, (int) $row->status, (int) $row->is_anonymous,
        ]);

        // 只能评一次，评过之后订单详情里标出来
        $this->review($order, $itemId)->assertJsonPath('code', 1)->assertJsonPath('msg', '这件商品已经评价过了');
        $this->assertSame('You have already reviewed this item', $this->api('POST', "/orders/{$order->order_no}/reviews", ['order_item_id' => $itemId, 'rating' => 5], $this->token, ['X-Lang' => 'en'])->json('msg'));
        $detail = $this->api('GET', "/orders/{$order->order_no}", [], $this->token)->json('data.items.0');
        $this->assertSame([false, true], [$detail['can_review'], $detail['reviewed']]);
        $this->assertSame(1, GoodsReview::query()->count());
    }

    public function test_order_list_has_a_to_review_tab(): void
    {
        $tab = fn (?string $token = null) => array_column($this->api('GET', '/orders?tab=to_review', [], $token ?? $this->token)->json('data.list'), 'order_no');

        // 没完成的订单不算
        $this->pay($this->placeOrder($this->token, $this->sku, 1, $this->addressId));
        $this->assertSame([], $tab());

        [$a, $itemA] = $this->finishedOrder();
        [$b, $itemB] = $this->finishedOrder();
        $this->assertSame([$b->order_no, $a->order_no], $tab());

        // 评过的不再出现；别人看不到我的
        $this->review($a, $itemA)->assertJsonPath('code', 0);
        $this->assertSame([$b->order_no], $tab());
        [, $otherToken] = $this->makeMember();
        $this->assertSame([], $tab($otherToken));

        // 完成后全部退款的商品不能评，这张订单也就不在待评价里（它已经不是“已完成”了）
        $r = $this->api('POST', "/orders/{$b->order_no}/refunds", ['order_item_id' => $itemB, 'qty' => 1, 'type' => 1, 'reason' => '测试'], $this->token);
        $id = (int) OrderRefund::query()->where('refund_no', $r->json('data.refund_no'))->value('id');
        app(OrderRefundService::class)->approve($id, 1, null);
        app(OrderRefundService::class)->pay($id, 1, true);
        $this->assertSame([], $tab());
        // “已完成”页签不受影响
        $this->assertSame([$a->order_no], array_column($this->api('GET', '/orders?tab=finished', [], $this->token)->json('data.list'), 'order_no'));
    }

    public function test_fully_refunded_items_and_other_peoples_orders_cannot_be_reviewed(): void
    {
        [$order, $itemId] = $this->finishedOrder();
        // 完成后全部退款
        $r = $this->api('POST', "/orders/{$order->order_no}/refunds", ['order_item_id' => $itemId, 'qty' => 1, 'type' => 1, 'reason' => '测试'], $this->token);
        $id = (int) OrderRefund::query()->where('refund_no', $r->json('data.refund_no'))->value('id');
        app(OrderRefundService::class)->approve($id, 1, null);
        app(OrderRefundService::class)->pay($id, 1, true);
        $this->assertNotSame(0, $this->review($order->refresh(), $itemId)->json('code'));

        // 别人的订单
        [$order2, $item2] = $this->finishedOrder();
        [$other, $otherToken] = $this->makeMember();
        $this->review($order2, $item2, [], $otherToken)->assertJsonPath('code', 1)->assertJsonPath('msg', '订单不存在');
        // 自己的订单号配别人订单里的商品行
        [$mine] = $this->finishedOrder($otherToken, (int) $this->makeAddress($other->id)->id);
        $this->review($mine, $item2, [], $otherToken)->assertJsonPath('msg', '订单里没有这件商品');
        $this->api('POST', "/orders/{$order2->order_no}/reviews", ['order_item_id' => $item2, 'rating' => 5])->assertJsonPath('code', 401);

        $this->assertSame(0, GoodsReview::query()->count());
    }

    public function test_review_images_must_be_the_buyers_own_uploads(): void
    {
        [$order, $itemId] = $this->finishedOrder();
        [$other] = $this->makeMember();
        $mine = $this->uploaded($this->memberId, 'a.png');

        foreach ([['https://evil.example/x.png'], [$this->uploaded((int) $other->id, 'b.png')], [str_replace('a.png', 'never.png', $mine)]] as $bad) {
            $this->review($order, $itemId, ['images' => $bad])->assertJsonPath('code', 1);
        }
        $this->assertNotSame(0, $this->review($order, $itemId, ['images' => array_fill(0, 7, $mine)])->json('code'), '最多 6 张');
        $this->assertSame(0, GoodsReview::query()->count());

        $this->review($order, $itemId, ['images' => [$mine]])->assertJsonPath('code', 0);
        $this->assertSame([$mine], GoodsReview::query()->firstOrFail()->images);

        // 上传接口接受“评价”这个用途
        $this->assertContains('review', UploadService::MEMBER_SCENES);
    }

    public function test_goods_page_shows_reviews_summary_filters_and_hides_anonymous_names(): void
    {
        [$b, $bToken] = $this->makeMember(['nickname' => '李雷', 'avatar' => 'https://cdn.test/b.png']);
        $bAddr = (int) $this->makeAddress($b->id)->id;

        [$o1, $i1] = $this->finishedOrder();
        $this->review($o1, $i1, ['rating' => 5, 'content' => '很好', 'images' => [$this->uploaded($this->memberId, 'p.png')]])->assertJsonPath('code', 0);
        [$o2, $i2] = $this->finishedOrder($bToken, $bAddr);
        $this->review($o2, $i2, ['rating' => 3, 'content' => '一般', 'is_anonymous' => true], $bToken)->assertJsonPath('code', 0);
        [$o3, $i3] = $this->finishedOrder($bToken, $bAddr);
        $this->review($o3, $i3, ['rating' => 1, 'content' => '不满意'], $bToken)->assertJsonPath('code', 0);

        // 不用登录就能看；新的在前
        $all = $this->api('GET', "/goods/{$this->goodsId}/reviews");
        $all->assertJsonPath('code', 0)->assertJsonPath('data.total', 3);
        $this->assertSame(['count' => 3, 'good_rate' => 33, 'avg' => '3.0', 'good' => 1, 'mid' => 1, 'bad' => 1, 'with_images' => 1], $all->json('data.summary'));
        $this->assertSame(['不满意', '一般', '很好'], array_column($all->json('data.list'), 'content'));

        // 匿名的那条：昵称打码、不带头像；不匿名的原样显示
        $anon = $all->json('data.list.1');
        $this->assertSame(['李***', ''], [$anon['nickname'], $anon['avatar']]);
        $this->assertSame(['张小明', 'https://cdn.test/a.png'], [$all->json('data.list.2.nickname'), $all->json('data.list.2.avatar')]);
        // 不泄露会员 ID、订单号
        $this->assertStringNotContainsString($o2->order_no, $all->getContent());
        $this->assertArrayNotHasKey('member_id', $anon);

        foreach (['good' => ['很好'], 'mid' => ['一般'], 'bad' => ['不满意'], 'image' => ['很好'], 'nonsense' => ['不满意', '一般', '很好']] as $level => $expect) {
            $this->assertSame($expect, array_column($this->api('GET', "/goods/{$this->goodsId}/reviews?level={$level}")->json('data.list'), 'content'), $level);
        }
        $this->assertCount(2, $this->api('GET', "/goods/{$this->goodsId}/reviews?size=2")->json('data.list'));

        // 商品详情带概况
        $this->assertSame(3, $this->api('GET', "/goods/{$this->goodsId}")->json('data.review.count'));
        // 没有评价的商品：0 条，好评率按 100 显示
        $empty = (int) DB::table('goods_sku')->where('id', $this->makeSku('10.00', 5))->value('goods_id');
        $this->assertSame([0, 100], [$this->api('GET', "/goods/{$empty}")->json('data.review.count'), $this->api('GET', "/goods/{$empty}")->json('data.review.good_rate')]);
    }

    public function test_admin_can_hide_restore_and_reply(): void
    {
        [$order, $itemId] = $this->finishedOrder();
        $this->review($order, $itemId, ['rating' => 2, 'content' => '包装破了'])->assertJsonPath('code', 0);
        $id = (int) GoodsReview::query()->value('id');
        $this->assertSame(1, $this->api('GET', "/goods/{$this->goodsId}")->json('data.review.count'));

        // 后台列表：能按内容、订单号、星级、状态筛选
        $list = $this->adminApi('GET', '/goods/review/list');
        $list->assertJsonPath('code', 0)->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.order_no', $order->order_no)->assertJsonPath('data.list.0.member', '张小明');
        $this->assertSame(1, $this->adminApi('GET', '/goods/review/list?keyword=包装')->json('data.total'));
        $this->assertSame(1, $this->adminApi('GET', '/goods/review/list?keyword='.$order->order_no)->json('data.total'));
        $this->assertSame(0, $this->adminApi('GET', '/goods/review/list?rating=5')->json('data.total'));
        $this->assertSame(0, $this->adminApi('GET', '/goods/review/list?status=0')->json('data.total'));
        $this->assertSame(1, $this->adminApi('GET', '/goods/review/list?replied=0')->json('data.total'));

        // 隐藏：商品页看不到，统计立即更新；买家仍然不能重新评价
        $this->adminApi('POST', "/goods/review/{$id}/status", ['status' => 0])->assertJsonPath('code', 0);
        $this->assertSame(0, $this->api('GET', "/goods/{$this->goodsId}/reviews")->json('data.total'));
        $this->assertSame(0, $this->api('GET', "/goods/{$this->goodsId}")->json('data.review.count'));
        $this->review($order, $itemId)->assertJsonPath('msg', '这件商品已经评价过了');

        // 恢复并回复
        $this->adminApi('POST', "/goods/review/{$id}/status", ['status' => 1])->assertJsonPath('code', 0);
        $this->adminApi('POST', "/goods/review/{$id}/reply", ['reply' => ' 抱歉，已为您补发 '])->assertJsonPath('code', 0);
        $shown = $this->api('GET', "/goods/{$this->goodsId}/reviews")->json('data.list.0');
        $this->assertSame('抱歉，已为您补发', $shown['reply']);
        $this->assertNotNull($shown['replied_at']);
        $this->assertSame(1, $this->adminApi('GET', '/goods/review/list?replied=1')->json('data.total'));

        // 回复留空 = 删除回复；参数不对、评价不存在都被拒绝
        $this->adminApi('POST', "/goods/review/{$id}/reply", ['reply' => ''])->assertJsonPath('code', 0);
        $this->assertSame('', $this->api('GET', "/goods/{$this->goodsId}/reviews")->json('data.list.0.reply'));
        $this->assertNotSame(0, $this->adminApi('POST', "/goods/review/{$id}/status", ['status' => 5])->json('code'));
        $this->assertNotSame(0, $this->adminApi('POST', "/goods/review/{$id}/status")->json('code'));
        $this->assertNotSame(0, $this->adminApi('POST', '/goods/review/999999/reply', ['reply' => 'x'])->json('code'));
        // 评价内容本身后台改不了
        $this->assertSame('包装破了', GoodsReview::query()->find($id)->content);
    }

    public function test_buyer_can_append_once_and_merchant_replies_to_it_separately(): void
    {
        [$order, $itemId] = $this->finishedOrder();
        $uri = "/orders/{$order->order_no}/reviews/append";
        $item = fn () => $this->api('GET', "/orders/{$order->order_no}", [], $this->token)->json('data.items.0');

        // 没评价过不能追评
        $this->assertFalse($item()['can_append']);
        $this->api('POST', $uri, ['order_item_id' => $itemId, 'content' => '先追评'], $this->token)->assertJsonPath('msg', '这件商品还没有评价，不能追评');

        $this->review($order, $itemId, ['rating' => 4, 'content' => '刚收到，看着不错'])->assertJsonPath('code', 0);
        $id = (int) GoodsReview::query()->value('id');
        DB::table('goods_review')->where('id', $id)->update(['created_at' => now()->subDays(12)]);
        $this->assertTrue($item()['can_append']);
        $this->assertNull($this->api('GET', "/goods/{$this->goodsId}/reviews")->json('data.list.0.append'));
        $this->assertSame(0, $this->api('GET', "/goods/{$this->goodsId}/reviews?level=image")->json('data.total'));

        // 内容必填；图片必须是自己上传的
        $this->api('POST', $uri, ['order_item_id' => $itemId, 'content' => ''], $this->token)->assertJsonPath('code', 422);
        $this->api('POST', $uri, ['order_item_id' => $itemId, 'content' => '用了两周', 'images' => ['https://evil.example.com/x.png']], $this->token)->assertJsonPath('msg', '图片无效，请重新上传');
        $this->assertTrue($item()['can_append']);

        $img = $this->uploaded($this->memberId, 'after.png');
        $this->api('POST', $uri, ['order_item_id' => $itemId, 'content' => ' 用了两周，猫很喜欢 ', 'images' => [$img]], $this->token)->assertJsonPath('code', 0)->assertJsonPath('msg', '追评成功');

        // 商品页：追评跟在原评价下面，带天数；星级和好评率不变；“有图”把追评的图也算上
        $data = $this->api('GET', "/goods/{$this->goodsId}/reviews")->json('data');
        $append = $data['list'][0]['append'];
        $this->assertSame(['用了两周，猫很喜欢', [$img], 12, ''], [$append['content'], $append['images'], $append['days'], $append['reply']]);
        $this->assertSame([4, '刚收到，看着不错'], [$data['list'][0]['rating'], $data['list'][0]['content']]);
        $this->assertSame([1, 100, 1], [$data['summary']['count'], $data['summary']['good_rate'], $data['summary']['with_images']]);
        $this->assertSame(1, $this->api('GET', "/goods/{$this->goodsId}/reviews?level=image")->json('data.total'));

        // 只能追一次
        $this->assertFalse($item()['can_append']);
        $this->api('POST', $uri, ['order_item_id' => $itemId, 'content' => '再追一次'], $this->token)->assertJsonPath('msg', '这件商品已经追评过了');
        $this->assertSame('用了两周，猫很喜欢', GoodsReview::query()->find($id)->append_content);

        // 后台：能搜到追评的内容；对追评的回复和对原评价的回复分开
        $row = $this->adminApi('GET', '/goods/review/list?keyword=猫很喜欢')->json('data.list.0');
        $this->assertSame([$id, '用了两周，猫很喜欢'], [$row['id'], $row['append']['content']]);
        $this->adminApi('POST', "/goods/review/{$id}/reply", ['reply' => '感谢支持'])->assertJsonPath('code', 0);
        $this->adminApi('POST', "/goods/review/{$id}/reply", ['reply' => '谢谢回来分享', 'append' => 1])->assertJsonPath('code', 0);
        $shown = $this->api('GET', "/goods/{$this->goodsId}/reviews")->json('data.list.0');
        $this->assertSame(['感谢支持', '谢谢回来分享'], [$shown['reply'], $shown['append']['reply']]);
        $this->assertNotNull($shown['append']['replied_at']);

        // 没有追评的评价不能回复追评；别人不能替他追评
        [$other, $otherItem] = $this->finishedOrder();
        $this->review($other, $otherItem)->assertJsonPath('code', 0);
        $otherId = (int) GoodsReview::query()->where('order_id', $other->id)->value('id');
        $this->adminApi('POST', "/goods/review/{$otherId}/reply", ['reply' => 'x', 'append' => 1])->assertJsonPath('msg', '这条评价没有追评');
        [$b, $bToken] = $this->makeMember();
        $this->api('POST', "/orders/{$other->order_no}/reviews/append", ['order_item_id' => $otherItem, 'content' => '乙来追评'], $bToken)->assertJsonPath('msg', '订单不存在');
        [$bOrder] = $this->finishedOrder($bToken, (int) $this->makeAddress($b->id)->id);
        $this->api('POST', "/orders/{$bOrder->order_no}/reviews/append", ['order_item_id' => $otherItem, 'content' => '串单追评'], $bToken)->assertJsonPath('msg', '这件商品还没有评价，不能追评');
        $this->assertNull(GoodsReview::query()->find($otherId)->append_at);
    }
}
