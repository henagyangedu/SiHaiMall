<?php

namespace Tests\Feature;

use App\Models\DriverConfig;
use App\Models\Member;
use App\Services\Common\ConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/** 注册送积分和每日签到 */
class PointRewardTest extends FeatureTestCase
{
    private function setReward(int $register, int $signin): void
    {
        app(ConfigService::class)->save('trade', ['points_register' => $register, 'points_signin' => $signin]);
    }

    private function logs(int $memberId, int $type): array
    {
        return DB::table('member_point_log')->where('member_id', $memberId)->where('type', $type)->orderBy('id')
            ->get(['change_points', 'after_points', 'ref_no'])->map(fn ($r) => (array) $r)->all();
    }

    public function test_new_members_get_the_register_bonus_once_and_nothing_when_it_is_off(): void
    {
        // 默认不送
        $r = $this->api('POST', '/auth/register', ['account' => 'plain@example.com', 'password' => 'Secret#123']);
        $this->assertSame(0, $r->json('data.info.points'));
        $this->assertSame([], $this->logs((int) $r->json('data.info.id'), 1));

        $this->setReward(50, 0);
        $r = $this->api('POST', '/auth/register', ['account' => 'bonus@example.com', 'password' => 'Secret#123']);
        $r->assertJsonPath('code', 0)->assertJsonPath('data.info.points', 50)->assertJsonPath('data.info.growth', 50);
        $id = (int) $r->json('data.info.id');
        $this->assertSame([['change_points' => 50, 'after_points' => 50, 'ref_no' => '']], $this->logs($id, 1));

        // 之后再登录不会重复送；早先注册的会员也不补发
        $this->api('POST', '/auth/login', ['account' => 'bonus@example.com', 'password' => 'Secret#123'])->assertJsonPath('data.info.points', 50);
        $this->api('POST', '/auth/login', ['account' => 'plain@example.com', 'password' => 'Secret#123'])->assertJsonPath('data.info.points', 0);
    }

    public function test_wechat_first_login_counts_as_registering(): void
    {
        $this->setReward(30, 0);
        DriverConfig::query()->create(['type' => 'app', 'driver' => 'wechat_mini', 'name' => '微信小程序', 'is_enabled' => 1, 'is_default' => 0, 'sort' => 0,
            'config' => ['app_id' => 'wx1234567890abcdef', 'app_secret' => 'APP-SECRET']]);
        Http::fake(['api.weixin.qq.com/sns/jscode2session*' => Http::response(['openid' => 'o-reward', 'session_key' => 'K'])]);

        $first = $this->api('POST', '/auth/login-wechat-mini', ['code' => 'C1'], null, ['X-Source' => 'mp']);
        $first->assertJsonPath('data.is_new', true)->assertJsonPath('data.info.points', 30);
        $again = $this->api('POST', '/auth/login-wechat-mini', ['code' => 'C2'], null, ['X-Source' => 'mp']);
        $again->assertJsonPath('data.is_new', false)->assertJsonPath('data.info.points', 30);
        $this->assertCount(1, $this->logs((int) $first->json('data.info.id'), 1));
    }

    public function test_sign_in_once_a_day(): void
    {
        [$m, $token] = $this->makeMember();

        // 没开放：状态里说明，签到被拒绝
        $this->api('GET', '/member/signin', [], $token)->assertJsonPath('data.enabled', false)->assertJsonPath('data.signed_today', false);
        $this->api('POST', '/member/signin', [], $token)->assertJsonPath('code', 1)->assertJsonPath('msg', '签到功能未开启');

        $this->setReward(0, 5);
        $this->travelTo(now()->setTime(10, 0));
        $this->assertSame(['enabled' => true, 'points' => 5, 'signed_today' => false], $this->api('GET', '/member/signin', [], $token)->json('data'));

        $this->api('POST', '/member/signin', [], $token)->assertJsonPath('code', 0)->assertJsonPath('data.points', 5)->assertJsonPath('data.total', 5);
        $this->api('GET', '/member/signin', [], $token)->assertJsonPath('data.signed_today', true);
        $this->api('POST', '/member/signin', [], $token)->assertJsonPath('code', 1)->assertJsonPath('msg', '今天已经签到过了');
        $this->assertSame('You have already checked in today', $this->api('POST', '/member/signin', [], $token, ['X-Lang' => 'en'])->json('msg'));
        $this->assertSame(5, (int) $m->refresh()->points);

        // 当天晚些时候还是不行，过了零点就可以
        $this->travelTo(now()->setTime(23, 59));
        $this->api('POST', '/member/signin', [], $token)->assertJsonPath('code', 1);
        $this->travelTo(now()->addDay()->setTime(0, 1));
        $this->api('GET', '/member/signin', [], $token)->assertJsonPath('data.signed_today', false);
        $this->api('POST', '/member/signin', [], $token)->assertJsonPath('data.total', 10);

        $logs = $this->logs((int) $m->id, 2);
        $this->assertSame([5, 10], array_column($logs, 'after_points'));
        $this->assertNotSame($logs[0]['ref_no'], $logs[1]['ref_no']);

        // 别人签到互不影响；没登录不能签
        [, $other] = $this->makeMember();
        $this->api('POST', '/member/signin', [], $other)->assertJsonPath('data.total', 5);
        $this->api('POST', '/member/signin')->assertJsonPath('code', 401);
    }

    public function test_bonus_and_sign_in_points_count_toward_the_member_level(): void
    {
        DB::table('member_level')->updateOrInsert(['id' => 1], ['level_no' => 1, 'need_points' => 0, 'discount' => 100, 'status' => 1]);
        $silver = (int) DB::table('member_level')->insertGetId(['level_no' => 2, 'need_points' => 60, 'discount' => 95, 'status' => 1]);
        $this->setReward(50, 10);

        $r = $this->api('POST', '/auth/register', ['account' => 'grow@example.com', 'password' => 'Secret#123']);
        $id = (int) $r->json('data.info.id');
        $this->assertSame(1, (int) $r->json('data.info.level_id'), '注册送的 50 还不够 60');

        // 签到一次到 60，升级
        $this->api('POST', '/member/signin', [], $r->json('data.token'))->assertJsonPath('code', 0);
        $this->assertSame($silver, (int) Member::query()->find($id)->level_id);
        $this->api('GET', '/member/profile', [], $r->json('data.token'))->assertJsonPath('data.growth', 60)->assertJsonPath('data.level_discount', '95.00');
    }

    public function test_admin_can_set_the_rewards_in_trade_settings(): void
    {
        $this->adminApi('PUT', '/config/trade', ['points_register' => 100, 'points_signin' => 3])->assertJsonPath('code', 0);
        $saved = $this->adminApi('GET', '/config/trade')->json('data');
        $this->assertSame([100, 3], [(int) $saved['points_register'], (int) $saved['points_signin']]);

        $this->assertNotSame(0, $this->adminApi('PUT', '/config/trade', ['points_signin' => -1])->json('code'));
        $this->assertNotSame(0, $this->adminApi('PUT', '/config/trade', ['points_register' => 1000000])->json('code'));
    }
}
