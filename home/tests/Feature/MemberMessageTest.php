<?php

namespace Tests\Feature;

use App\Mail\TemplateMail;
use App\Services\Common\MemberMessageService;
use App\Services\Common\NotifyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\FeatureTestCase;

/** 站内信：通知默认写进消息中心，邮件另外开关；买家看、读、删自己的消息 */
class MemberMessageTest extends FeatureTestCase
{
    private function notify(string $scene, int $memberId, array $vars): bool
    {
        return app(NotifyService::class)->deliver($scene, $memberId, $vars);
    }

    public function test_site_message_is_on_by_default_and_email_is_separate(): void
    {
        Mail::fake();
        [$m, $token] = $this->makeMember();
        $vars = ['order_no' => 'GM0001', 'express_name' => '顺丰', 'express_no' => 'SF123'];

        // 什么都没配：站内信发，邮件不发
        $this->assertTrue($this->notify('order_shipped', (int) $m->id, $vars));
        Mail::assertNothingSent();
        $list = $this->api('GET', '/messages', [], $token)->json('data');
        $this->assertSame([1, 1], [$list['total'], $list['unread']]);
        $msg = $list['list'][0];
        $this->assertSame(['order_shipped', 'order', 'GM0001', false], [$msg['scene'], $msg['link_type'], $msg['link_value'], $msg['is_read']]);
        $this->assertStringContainsString('GM0001', $msg['title']);
        $this->assertStringContainsString('SF123', $msg['content']);

        // 打开邮件：两个都发，内容一样
        $this->adminApi('PUT', '/message/order_shipped', ['is_enabled' => 1])->assertJsonPath('code', 0);
        $this->assertTrue($this->notify('order_shipped', (int) $m->id, $vars));
        Mail::assertSent(TemplateMail::class, 1);
        $this->assertSame(2, DB::table('member_message')->where('member_id', $m->id)->count());

        // 关掉站内信：只发邮件；后台列表里两个开关各自独立
        $this->adminApi('PUT', '/message/order_shipped', ['site_enabled' => 0])->assertJsonPath('code', 0);
        $row = collect($this->adminApi('GET', '/message/list')->json('data.scenes'))->firstWhere('scene', 'order_shipped');
        $this->assertSame([1, 0], [$row['is_enabled'], $row['site_enabled']]);
        $other = collect($this->adminApi('GET', '/message/list')->json('data.scenes'))->firstWhere('scene', 'order_paid');
        $this->assertSame([0, 1], [$other['is_enabled'], $other['site_enabled']], '没动过的场景：邮件关、站内信开');
        $this->assertTrue($this->notify('order_shipped', (int) $m->id, $vars));
        Mail::assertSent(TemplateMail::class, 2);
        $this->assertSame(2, DB::table('member_message')->where('member_id', $m->id)->count());

        // 两个都关：不发
        $this->adminApi('PUT', '/message/order_shipped', ['is_enabled' => 0])->assertJsonPath('code', 0);
        $this->assertFalse($this->notify('order_shipped', (int) $m->id, $vars));
        // 只切换邮件开关，不会把站内信的开关带回默认值
        $row = collect($this->adminApi('GET', '/message/list')->json('data.scenes'))->firstWhere('scene', 'order_shipped');
        $this->assertSame([0, 0], [$row['is_enabled'], $row['site_enabled']]);
    }

    public function test_message_uses_member_language_and_customised_template(): void
    {
        [$zh] = $this->makeMember();
        [$en] = $this->makeMember(['lang' => 'en']);
        [$noEmail, $token] = $this->makeMember(['email' => '']);

        // 自定义了中文模板：站内信用它（和邮件共用一份文案）；英文没填，按规则先回退到默认语言的自定义模板
        $this->adminApi('PUT', '/message/withdraw_paid', ['title' => ['zh-CN' => '提现 {withdraw_no} 到账啦'], 'content' => ['zh-CN' => '金额 {amount}，{nickname} 请查收']])->assertJsonPath('code', 0);
        foreach ([$zh, $en, $noEmail] as $m) {
            $this->assertTrue($this->notify('withdraw_paid', (int) $m->id, ['withdraw_no' => 'WD9', 'amount' => '88.00']));
        }

        $row = fn ($m) => DB::table('member_message')->where('member_id', $m->id)->first();
        $this->assertSame(['提现 WD9 到账啦', "金额 88.00，{$zh->nickname} 请查收"], [$row($zh)->title, $row($zh)->content]);
        $this->assertSame('提现 WD9 到账啦', $row($en)->title);
        // 没有邮箱的会员照样收到站内信；提现的消息点了去分销中心，不带值
        $this->assertSame(['withdraw', ''], [$row($noEmail)->link_type, $row($noEmail)->link_value]);
        $this->api('GET', '/messages/unread-count', [], $token)->assertJsonPath('data.unread', 1);
    }

    public function test_read_delete_and_cleanup(): void
    {
        [$m, $token] = $this->makeMember();
        $service = app(MemberMessageService::class);
        foreach (range(1, 3) as $i) {
            $service->push((int) $m->id, 'order_paid', "标题{$i}", "正文{$i}", ['order', "GM{$i}"]);
        }
        $ids = DB::table('member_message')->where('member_id', $m->id)->orderBy('id')->pluck('id')->all();

        $this->api('GET', '/messages')->assertJsonPath('code', 401);
        // 新的在前
        $this->assertSame(['标题3', '标题2', '标题1'], array_column($this->api('GET', '/messages', [], $token)->json('data.list'), 'title'));

        $this->api('POST', "/messages/{$ids[0]}/read", [], $token)->assertJsonPath('code', 0);
        $this->api('POST', "/messages/{$ids[0]}/read", [], $token)->assertJsonPath('code', 0);
        $this->api('GET', '/messages/unread-count', [], $token)->assertJsonPath('data.unread', 2);

        $this->api('DELETE', "/messages/{$ids[1]}", [], $token)->assertJsonPath('code', 0);
        $this->api('DELETE', "/messages/{$ids[1]}", [], $token)->assertJsonPath('code', 1)->assertJsonPath('msg', '消息不存在');
        $this->api('GET', '/messages/unread-count', [], $token)->assertJsonPath('data.unread', 1);

        $this->api('POST', '/messages/read-all', [], $token)->assertJsonPath('code', 0);
        $list = $this->api('GET', '/messages', [], $token)->json('data');
        $this->assertSame([2, 0], [$list['total'], $list['unread']]);

        // 超过保留期的被清理，没到期的留着
        DB::table('member_message')->where('id', $ids[0])->update(['created_at' => now()->subDays(MemberMessageService::KEEP_DAYS + 1)]);
        $this->artisan('mall:message-cleanup')->assertSuccessful();
        $this->assertSame([$ids[2]], DB::table('member_message')->where('member_id', $m->id)->pluck('id')->all());
    }

    public function test_business_events_write_messages(): void
    {
        // 走真实的业务节点：支付成功后，买家的消息中心里有一条能跳到这张订单的消息
        [$m, $token] = $this->makeMember();
        $order = $this->placeOrder($token, $this->makeSku('100.00', 5), 1, (int) $this->makeAddress((int) $m->id)->id);
        $this->pay($order);

        $msg = $this->api('GET', '/messages', [], $token)->json('data.list.0');
        $this->assertSame(['order_paid', 'order', $order->order_no], [$msg['scene'], $msg['link_type'], $msg['link_value']]);
    }
}
