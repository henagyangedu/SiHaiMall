<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberCoupon;
use App\Services\Common\ConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\FeatureTestCase;

/** 隐私：注册时同意协议、自助注销账号、导出自己的数据、本地存储告知条的开关 */
class MemberPrivacyTest extends FeatureTestCase
{
    public function test_registration_requires_consent_once_a_policy_is_published(): void
    {
        $reg = fn (string $account, array $extra = [], array $headers = []) => $this->api('POST', '/auth/register', ['account' => $account, 'password' => 'secret123'] + $extra, null, $headers);

        // 后台还没填协议：不用同意，也不记时间
        $this->assertFalse($this->api('GET', '/config/init')->json('data.agree_required'));
        $reg('a1@example.com')->assertJsonPath('code', 0);
        $this->assertNull(Member::query()->where('account', 'a1@example.com')->value('agreed_at'));

        // 填了隐私政策之后：必须同意
        app(ConfigService::class)->save('protocol', ['privacy_policy' => ['zh-CN' => '<p>我们如何处理你的信息</p>']]);
        $this->assertTrue($this->api('GET', '/config/init')->json('data.agree_required'));
        $this->assertSame('请先阅读并同意用户协议和隐私政策', $reg('a2@example.com')->json('msg'));
        $this->assertSame('请先阅读并同意用户协议和隐私政策', $reg('a2@example.com', ['agree' => 0])->json('msg'));
        $this->assertSame('Please read and accept the Terms of Service and Privacy Policy first', $reg('a2@example.com', [], ['X-Lang' => 'en'])->json('msg'));
        $this->assertFalse(Member::query()->where('account', 'a2@example.com')->exists());

        $reg('a2@example.com', ['agree' => 1])->assertJsonPath('code', 0);
        $this->assertNotNull(Member::query()->where('account', 'a2@example.com')->value('agreed_at'));
        // 后台会员详情里看得到
        $id = Member::query()->where('account', 'a2@example.com')->value('id');
        $this->assertNotNull($this->adminApi('GET', "/member/list/{$id}")->json('data.agreed_at'));
    }

    public function test_storage_notice_switch_is_exposed_to_buyers(): void
    {
        $this->assertSame(0, (int) $this->api('GET', '/config/init')->json('data.site.storage_notice_enabled'));
        $this->adminApi('PUT', '/config/base', ['storage_notice_enabled' => 1])->assertJsonPath('code', 0);
        $this->assertSame(1, (int) $this->api('GET', '/config/init')->json('data.site.storage_notice_enabled'));
    }

    public function test_cancel_is_blocked_by_open_orders_and_needs_the_password(): void
    {
        [$member, $token] = $this->makeMember(['password' => Hash::make('secret123')]);
        $sku = $this->makeSku('100.00', 10);
        $order = $this->placeOrder($token, $sku, 1, $this->makeAddress((int) $member->id)->id);

        // 有待支付的订单：不能注销，说明原因（英文买家看到英文）
        $check = $this->api('GET', '/member/cancel-check', [], $token);
        $this->assertSame([false, true, ['还有 1 张订单没有完成（待支付、待发货、待收货或售后中）']], [$check->json('data.can_cancel'), $check->json('data.has_password'), $check->json('data.blockers')]);
        $this->assertSame('You still have 1 open order(s) (unpaid, to ship, to receive or in after-sales)', $this->api('GET', '/member/cancel-check', [], $token, ['X-Lang' => 'en'])->json('data.blockers.0'));
        $this->assertSame('还有 1 张订单没有完成（待支付、待发货、待收货或售后中）', $this->api('POST', '/member/cancel', ['password' => 'secret123'], $token)->json('msg'));

        // 取消订单后可以注销，但要输对密码
        $this->api('POST', "/orders/{$order->order_no}/cancel", [], $token)->assertJsonPath('code', 0);
        $this->assertTrue($this->api('GET', '/member/cancel-check', [], $token)->json('data.can_cancel'));
        $this->assertSame('请输入登录密码', $this->api('POST', '/member/cancel', [], $token)->json('msg'));
        $this->assertSame('密码不正确', $this->api('POST', '/member/cancel', ['password' => 'wrong-one'], $token)->json('msg'));
        $this->assertTrue(Member::query()->whereKey($member->id)->exists());

        // 分销员还有没结清的佣金：不能注销
        DB::table('distributor')->insert(['member_id' => $member->id, 'status' => 1, 'available_amount' => '12.50', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('还有没结清的佣金（待结算、可提现或提现中）', $this->api('POST', '/member/cancel', ['password' => 'secret123'], $token)->json('msg'));
    }

    public function test_cancel_erases_personal_data_and_keeps_order_records(): void
    {
        [$member, $token] = $this->makeMember(['password' => Hash::make('secret123'), 'email' => 'bye@example.com', 'account' => 'bye@example.com', 'nickname' => '要走的人', 'points' => 80]);
        $id = (int) $member->id;
        $sku = $this->makeSku('100.00', 10);
        $addressId = $this->makeAddress($id)->id;
        // 一张已取消的订单（留作记录）、一条评价、收藏、购物车、站内信、微信绑定、一张没用的券、一个下级
        $order = $this->placeOrder($token, $sku, 1, $addressId);
        $this->api('POST', "/orders/{$order->order_no}/cancel", [], $token)->assertJsonPath('code', 0);
        $goodsId = (int) DB::table('goods_sku')->where('id', $sku)->value('goods_id');
        $this->api('POST', "/favorites/{$goodsId}", [], $token)->assertJsonPath('code', 0);
        $this->api('POST', '/cart', ['sku_id' => $sku, 'qty' => 1], $token)->assertJsonPath('code', 0);
        DB::table('member_message')->insert(['member_id' => $id, 'scene' => 'broadcast', 'title' => '你好', 'content' => '内容', 'created_at' => now()]);
        DB::table('member_auth')->insert(['member_id' => $id, 'platform' => 'wechat_mini', 'app_id' => 'wx1', 'openid' => 'o1', 'unionid' => '', 'created_at' => now()]);
        DB::table('goods_review')->insert(['goods_id' => $goodsId, 'sku_id' => $sku, 'order_id' => $order->id, 'order_item_id' => 999001, 'member_id' => $id, 'rating' => 5, 'content' => '不错', 'is_anonymous' => 0, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $couponId = DB::table('coupon')->insertGetId(['name' => '券', 'type' => 1, 'amount' => '5', 'threshold' => '0', 'total' => 10, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('member_coupon')->insert(['member_id' => $id, 'coupon_id' => $couponId, 'status' => 0, 'valid_start' => now()->subDay(), 'valid_end' => now()->addDays(5), 'source' => 1, 'created_at' => now()]);
        [$child] = $this->makeMember(['parent_id' => $id]);

        // 导出：注销前能拿到自己的全部数据，拿不到别人的
        $export = $this->api('GET', '/member/export', [], $token);
        $export->assertJsonPath('code', 0);
        $this->assertSame(['bye@example.com', '要走的人', 80, ['wechat_mini']], [
            $export->json('data.profile.email'), $export->json('data.profile.nickname'), $export->json('data.profile.points'), $export->json('data.profile.linked_accounts'),
        ]);
        $this->assertSame([$order->order_no, 1, 1, 1, 1, 1, 1], [
            $export->json('data.orders.0.order_no'), count($export->json('data.orders.0.items')), count($export->json('data.addresses')),
            count($export->json('data.reviews')), count($export->json('data.favorites')), count($export->json('data.messages')), count($export->json('data.coupons')),
        ]);
        $this->assertNotEmpty($export->json('data.orders.0.shipping_address.name'));
        $this->assertStringNotContainsString('password', json_encode($export->json('data')));
        $this->json('GET', '/api/member/export')->assertJsonPath('code', 401);

        $check = $this->api('GET', '/member/cancel-check', [], $token);
        $this->assertSame([true, 80, 1], [$check->json('data.can_cancel'), $check->json('data.points'), $check->json('data.coupons')]);
        $this->api('POST', '/member/cancel', ['password' => 'secret123'], $token)->assertJsonPath('code', 0);

        // 登录失效，账号密码也登不上
        $this->api('GET', '/member/profile', [], $token)->assertJsonPath('code', 401);
        $this->assertNotSame(0, $this->api('POST', '/auth/login', ['account' => 'bye@example.com', 'password' => 'secret123'])->json('code'));
        // 个人信息抹掉了
        $gone = DB::table('member')->where('id', $id)->first();
        $this->assertNotNull($gone->deleted_at);
        $this->assertSame(["deleted_{$id}", '', '', '', '', '', 0], [$gone->account, $gone->email, $gone->phone, $gone->nickname, $gone->avatar, $gone->password, (int) $gone->points]);
        foreach (['member_token', 'member_auth', 'member_address', 'cart', 'member_favorite', 'member_message'] as $table) {
            $this->assertSame(0, DB::table($table)->where('member_id', $id)->count(), $table);
        }
        $this->assertSame(MemberCoupon::EXPIRED, (int) DB::table('member_coupon')->where('member_id', $id)->value('status'));
        $this->assertSame(0, (int) DB::table('member')->where('id', $child->id)->value('parent_id'));
        // 订单和它的收货信息快照保留；评价保留、变成匿名
        $this->assertSame(1, DB::table('order')->where('member_id', $id)->count());
        $this->assertNotEmpty(DB::table('order_address')->where('order_id', $order->id)->value('name'));
        $review = collect($this->api('GET', "/goods/{$goodsId}/reviews")->json('data.list'))->first();
        $this->assertSame(['***', '', '不错'], [$review['nickname'], $review['avatar'], $review['content']]);

        // 同一个邮箱可以重新注册，是一个新会员
        $again = $this->api('POST', '/auth/register', ['account' => 'bye@example.com', 'password' => 'secret456']);
        $again->assertJsonPath('code', 0);
        $this->assertNotSame($id, $again->json('data.info.id'));
        $this->assertSame(0, $again->json('data.info.points'));
    }

    public function test_members_without_a_password_can_cancel_without_one(): void
    {
        [$member, $token] = $this->makeMember(['password' => '']);
        $this->assertFalse($this->api('GET', '/member/cancel-check', [], $token)->json('data.has_password'));
        $this->api('POST', '/member/cancel', [], $token)->assertJsonPath('code', 0);
        $this->assertNotNull(DB::table('member')->where('id', $member->id)->value('deleted_at'));
    }
}
