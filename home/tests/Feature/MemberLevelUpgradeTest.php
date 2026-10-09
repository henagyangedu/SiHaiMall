<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Services\Admin\MemberService;
use App\Services\Common\ConfigService;
use App\Services\Common\MemberGrowthService;
use App\Services\Common\OrderRefundService;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;

/** 会员自动升级：成长值怎么算、什么时候升、只升不降、给会员看的等级信息 */
class MemberLevelUpgradeTest extends FeatureTestCase
{
    private string $token;

    private int $memberId;

    private int $addressId;

    private int $sku;

    private int $silver;

    private int $gold;

    protected function setUp(): void
    {
        parent::setUp();
        // 每消费 1 元送 2 积分；商品 100 元一件
        app(ConfigService::class)->save('trade', ['points_earn_enabled' => 1, 'points_earn_per_unit' => 2]);
        DB::table('member_level')->updateOrInsert(['id' => 1], ['level_no' => 1, 'need_points' => 0, 'discount' => 100, 'status' => 1]);
        $this->silver = $this->level(2, 300, '银牌会员', 'Silver');
        $this->gold = $this->level(3, 1000, '金牌会员', 'Gold');
        DB::table('member_level_lang')->updateOrInsert(['level_id' => 1, 'lang' => 'zh-CN'], ['name' => '普通会员']);

        [$m, $this->token] = $this->makeMember();
        $this->memberId = (int) $m->id;
        $this->addressId = (int) $this->makeAddress($m->id)->id;
        $this->sku = $this->makeSku('100.00', 50);
    }

    private function level(int $no, int $need, string $zh, string $en, int $status = 1): int
    {
        $id = (int) DB::table('member_level')->insertGetId(['level_no' => $no, 'need_points' => $need, 'discount' => 100, 'status' => $status]);
        DB::table('member_level_lang')->insert([['level_id' => $id, 'lang' => 'zh-CN', 'name' => $zh], ['level_id' => $id, 'lang' => 'en', 'name' => $en]]);

        return $id;
    }

    private function levelId(): int
    {
        return (int) DB::table('member')->where('id', $this->memberId)->value('level_id');
    }

    /** 买 $qty 件并走到“已完成”（这时才送积分） */
    private function buyAndFinish(int $qty): Order
    {
        $order = $this->pay($this->placeOrder($this->token, $this->sku, $qty, $this->addressId));
        $this->adminApi('POST', "/order/ship/{$order->id}", ['express_code' => 'SF', 'express_no' => 'SF1'])->assertJsonPath('code', 0);
        $this->api('POST', "/orders/{$order->order_no}/receive", [], $this->token)->assertJsonPath('code', 0);

        return $order;
    }

    private function profile(array $headers = []): array
    {
        return $this->api('GET', '/member/profile', [], $this->token, $headers)->json('data');
    }

    public function test_member_is_upgraded_when_completed_orders_bring_enough_growth(): void
    {
        $this->buyAndFinish(1);
        $this->assertSame(1, $this->levelId(), '200 成长值，还不到银牌的 300');
        $p = $this->profile();
        $this->assertSame([200, '普通会员', ['name' => '银牌会员', 'need_points' => 300, 'remain' => 100]], [$p['growth'], $p['level_name'], $p['next_level']]);

        $this->buyAndFinish(1);
        $this->assertSame($this->silver, $this->levelId(), '400 成长值，升到银牌');

        // 一次跨过多级门槛，直接升到最高的那一级
        $this->buyAndFinish(3);
        $this->assertSame($this->gold, $this->levelId());
        $p = $this->profile();
        $this->assertSame([1000, '金牌会员', null], [$p['growth'], $p['level_name'], $p['next_level']]);
    }

    public function test_spending_points_and_manual_adjustments_do_not_change_growth(): void
    {
        $this->buyAndFinish(1);

        // 后台手工加很多积分：余额变了，成长值和等级不变
        app(MemberService::class)->adjustPoints($this->memberId, 5000, '活动赠送', (int) $this->admin()->id);
        // 下单抵扣花掉积分
        app(ConfigService::class)->save('trade', ['points_deduct_enabled' => 1, 'points_per_unit' => 10, 'points_max_percent' => 50]);
        $this->pay($this->placeOrder($this->token, $this->sku, 1, $this->addressId, ['use_points' => true]));

        $this->artisan('mall:member-level-refresh')->assertSuccessful();
        $p = $this->profile();
        $this->assertSame(200, $p['growth']);
        $this->assertSame(1, $this->levelId());
        $this->assertGreaterThan(4000, $p['points']);
    }

    public function test_level_never_goes_down(): void
    {
        $order = $this->buyAndFinish(2);
        $this->assertSame($this->silver, $this->levelId());

        // 完成后全部退款：送的积分被扣回，成长值归零，但等级保留
        $itemId = (int) DB::table('order_item')->where('order_id', $order->id)->value('id');
        $r = $this->api('POST', "/orders/{$order->order_no}/refunds", ['order_item_id' => $itemId, 'qty' => 2, 'type' => 1, 'reason' => '测试'], $this->token);
        $this->assertSame(0, $r->json('code'), (string) $r->json('msg'));
        $id = (int) OrderRefund::query()->where('refund_no', $r->json('data.refund_no'))->value('id');
        app(OrderRefundService::class)->approve($id, 1, null);
        app(OrderRefundService::class)->pay($id, 1, true);

        $this->assertSame(0, $this->profile()['growth']);
        $this->artisan('mall:member-level-refresh')->assertSuccessful();
        $this->assertSame($this->silver, $this->levelId());

        // 管理员手工指定的更高等级不会被改低
        Member::query()->whereKey($this->memberId)->update(['level_id' => $this->gold]);
        $this->buyAndFinish(1);
        $this->assertSame($this->gold, $this->levelId());
    }

    public function test_daily_job_upgrades_existing_members_after_thresholds_change_and_skips_disabled_levels(): void
    {
        $this->buyAndFinish(1);
        // 一个停用的等级，门槛很低：不会升到它
        $disabled = $this->level(4, 100, '停用的等级', 'Disabled', 0);
        // 另一个没有任何积分记录的会员不受影响
        [$other] = $this->makeMember();

        $this->artisan('mall:member-level-refresh')->expectsOutput('已升级 0 位会员')->assertSuccessful();
        $this->assertSame(1, $this->levelId());

        // 把银牌的门槛从 300 调到 200：这个会员（成长值 200）在下一次任务里升级
        DB::table('member_level')->where('id', $this->silver)->update(['need_points' => 200]);
        $this->artisan('mall:member-level-refresh')->expectsOutput('已升级 1 位会员')->assertSuccessful();
        $this->assertSame($this->silver, $this->levelId());
        $this->assertNotSame($disabled, $this->levelId());
        $this->assertSame(1, (int) $other->refresh()->level_id);

        // 再跑一次什么都不变
        $this->artisan('mall:member-level-refresh')->expectsOutput('已升级 0 位会员')->assertSuccessful();
    }

    public function test_level_name_follows_the_members_language_and_login_returns_it_too(): void
    {
        $this->buyAndFinish(2);
        Member::query()->whereKey($this->memberId)->update(['lang' => 'en']);

        $p = $this->profile();
        $this->assertSame(['Silver', 'Gold'], [$p['level_name'], $p['next_level']['name']]);
        $this->assertSame(600, $p['next_level']['remain']);
        $this->assertSame(400, app(MemberGrowthService::class)->growth($this->memberId));

        $login = $this->api('POST', '/auth/login', ['account' => Member::query()->find($this->memberId)->account, 'password' => 'Secret#123']);
        $this->assertSame('Silver', $login->json('data.info.level_name'));
    }
}
