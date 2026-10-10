<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberLevel;
use App\Services\Common\MessageBroadcastService;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 后台群发消息：建好后分批写进会员的消息中心，按会员的语言、只发给选中的人，不重复 */
class MessageBroadcastTest extends FeatureTestCase
{
    private function create(array $data = []): int
    {
        return (int) $this->adminApi('POST', '/member/broadcast', $data + [
            'title' => ['zh-CN' => '周末满减活动', 'en' => 'Weekend sale'],
            'content' => ['zh-CN' => '全场满 200 减 20', 'en' => 'Save 20 on orders over 200'],
            'target_type' => 1,
        ])->assertJsonPath('code', 0)->json('data.id');
    }

    private function received(int $memberId): array
    {
        return DB::table('member_message')->where('member_id', $memberId)->where('scene', 'broadcast')->get()->all();
    }

    private function row(int $id): array
    {
        return collect($this->adminApi('GET', '/member/broadcast/list')->json('data.list'))->firstWhere('id', $id);
    }

    public function test_broadcast_to_all_uses_member_language_and_skips_disabled(): void
    {
        [$zh, $token] = $this->makeMember();
        [$en] = $this->makeMember(['lang' => 'en']);
        [$odd] = $this->makeMember(['lang' => 'xx']);
        [$off] = $this->makeMember(['status' => 0]);

        $id = $this->create(['link_type' => 'coupon']);
        // 建好只是待发送，还没有人收到
        $this->assertSame([0, 0, '全部会员'], [$this->row($id)['status'], $this->row($id)['sent_count'], $this->row($id)['target_text']]);
        $this->assertCount(0, $this->received((int) $zh->id));

        $active = Member::query()->where('status', 1)->count();
        $this->assertSame($active, app(MessageBroadcastService::class)->run());

        $this->assertSame(['周末满减活动', '全场满 200 减 20', 'coupon', ''], [
            $this->received((int) $zh->id)[0]->title, $this->received((int) $zh->id)[0]->content,
            $this->received((int) $zh->id)[0]->link_type, $this->received((int) $zh->id)[0]->link_value,
        ]);
        $this->assertSame('Weekend sale', $this->received((int) $en->id)[0]->title);
        $this->assertSame('周末满减活动', $this->received((int) $odd->id)[0]->title, '会员的语言没启用时按默认语言发');
        $this->assertCount(0, $this->received((int) $off->id), '禁用的会员不发');
        $this->assertSame([2, $active], [$this->row($id)['status'], $this->row($id)['sent_count']]);

        // 再跑一遍不会重复发
        $this->assertSame(0, app(MessageBroadcastService::class)->run());
        $this->assertCount(1, $this->received((int) $zh->id));

        // 买家在消息中心里看到它，带未读
        $list = $this->api('GET', '/messages', [], $token)->json('data');
        $this->assertSame([1, 'broadcast', 'coupon'], [$list['unread'], $list['list'][0]['scene'], $list['list'][0]['link_type']]);
    }

    public function test_language_without_translation_falls_back_to_default(): void
    {
        [$en] = $this->makeMember(['lang' => 'en']);
        $id = $this->create(['title' => ['zh-CN' => '只有中文'], 'content' => ['zh-CN' => '正文'], 'target_type' => 3, 'members' => [(string) $en->account]]);
        app(MessageBroadcastService::class)->run($id);

        $this->assertSame('只有中文', $this->received((int) $en->id)[0]->title);
    }

    public function test_target_by_level_and_by_member(): void
    {
        $vip = MemberLevel::query()->create(['level_no' => 9, 'need_points' => 0, 'discount' => 100, 'status' => 1]);
        DB::table('member_level_lang')->insert(['level_id' => $vip->id, 'lang' => 'zh-CN', 'name' => '金卡']);
        [$a] = $this->makeMember(['level_id' => $vip->id]);
        [$b] = $this->makeMember();
        [$c] = $this->makeMember();

        $byLevel = $this->create(['target_type' => 2, 'level_ids' => [$vip->id]]);
        $this->assertSame('等级：金卡', $this->row($byLevel)['target_text']);
        $this->adminApi('POST', "/member/broadcast/{$byLevel}/send")->assertJsonPath('data.status', 2)->assertJsonPath('data.sent_count', 1);
        $this->assertSame([1, 0], [count($this->received((int) $a->id)), count($this->received((int) $b->id))]);

        // 指定会员：ID、账号都认，重复的只算一个
        $byMember = $this->create(['target_type' => 3, 'members' => [(string) $b->id, (string) $c->account, (string) $b->account]]);
        $this->assertSame('2 位指定会员', $this->row($byMember)['target_text']);
        $this->adminApi('POST', "/member/broadcast/{$byMember}/send")->assertJsonPath('data.sent_count', 2);
        $this->assertSame([1, 1, 1], [count($this->received((int) $a->id)), count($this->received((int) $b->id)), count($this->received((int) $c->id))]);
        // 发完了不能再发
        $this->adminApi('POST', "/member/broadcast/{$byMember}/send")->assertJsonPath('msg', '这条消息已经发完了');
    }

    public function test_large_audience_is_sent_in_batches_without_duplicates(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = (int) $this->makeMember()[0]->id;
        }
        $id = $this->create(['target_type' => 3, 'members' => array_map('strval', $ids)]);
        $service = app(MessageBroadcastService::class);

        // 每批 2 人、这次只跑 1 批：发了 2 人，状态是发送中
        $this->assertSame(2, $service->run($id, 1, 2));
        $this->assertSame([1, 2], [$this->row($id)['status'], $this->row($id)['sent_count']]);
        // 发送中的不能撤回
        $this->adminApi('POST', "/member/broadcast/{$id}/revoke")->assertJsonPath('msg', '只有还没开始发送的消息可以撤回');

        // 接着发：剩下的 3 人，每人只有一条
        $this->assertSame(3, $service->run($id, 40, 2));
        $this->assertSame([2, 5], [$this->row($id)['status'], $this->row($id)['sent_count']]);
        $this->assertSame(5, DB::table('member_message')->whereIn('member_id', $ids)->where('scene', 'broadcast')->count());
        $this->assertSame(5, DB::table('member_message')->whereIn('member_id', $ids)->where('scene', 'broadcast')->distinct()->count('member_id'));
    }

    public function test_validation_and_revoke(): void
    {
        [$m] = $this->makeMember();
        $base = ['title' => ['zh-CN' => '标题'], 'content' => ['zh-CN' => '正文'], 'target_type' => 1];
        $before = DB::table('message_broadcast')->count();

        $this->adminApi('POST', '/member/broadcast', ['title' => ['en' => 'Only English']] + $base)->assertJsonPath('msg', '请填写默认语言（zh-CN）的标题');
        $this->adminApi('POST', '/member/broadcast', ['target_type' => 2] + $base)->assertJsonPath('msg', '请选择会员等级');
        $this->adminApi('POST', '/member/broadcast', ['target_type' => 3, 'members' => [(string) $m->account, 'nobody@example.com']] + $base)
            ->assertJsonPath('msg', '找不到这些会员（或已被禁用）：nobody@example.com');
        $this->adminApi('POST', '/member/broadcast', ['link_type' => 'goods', 'link_value' => 99999999] + $base)->assertJsonPath('msg', '要跳转的商品不存在');
        $this->assertSame($before, DB::table('message_broadcast')->count(), '校验没过的都不该存下来');

        // 待发送的可以撤回，撤回后定时任务不会发它
        $id = $this->create();
        $this->adminApi('POST', "/member/broadcast/{$id}/revoke")->assertJsonPath('code', 0);
        $this->assertSame(3, $this->row($id)['status']);
        $this->assertSame(0, app(MessageBroadcastService::class)->run());
        $this->assertCount(0, $this->received((int) $m->id));
        $this->adminApi('POST', "/member/broadcast/{$id}/send")->assertJsonPath('msg', '这条消息已撤回');
        $this->adminApi('POST', '/member/broadcast/99999999/revoke')->assertJsonPath('msg', '群发消息不存在');
    }
}
