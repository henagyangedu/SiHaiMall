<?php

namespace Tests\Feature;

use App\Models\DriverConfig;
use App\Models\Member;
use App\Services\Common\DistributionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/** 微信小程序一键登录：自动注册、再次登录、邀请码、同一微信身份合并、各种失败情况、后台配置 */
class WechatMiniLoginTest extends FeatureTestCase
{
    /** 假的微信接口下一次返回什么（同一个地址多次 Http::fake 不会覆盖，所以用一个可变的应答） */
    private array $wx = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([
            'api.weixin.qq.com/sns/jscode2session*' => fn () => Http::response($this->wx),
            'api.weixin.qq.com/cgi-bin/token*' => fn (Request $r) => Http::response(
                str_contains($r->url(), 'secret=APP-SECRET') ? ['access_token' => 'TOKEN', 'expires_in' => 7200] : ['errcode' => 40125, 'errmsg' => 'invalid appsecret']
            ),
        ]);
    }

    private function enable(): void
    {
        DriverConfig::query()->create(['type' => 'app', 'driver' => 'wechat_mini', 'name' => '微信小程序', 'is_enabled' => 1, 'is_default' => 0, 'sort' => 0,
            'config' => ['app_id' => 'wx1234567890abcdef', 'app_secret' => 'APP-SECRET']]);
    }

    private function login(string $openid, array $extra = [], array $headers = [], string $unionid = '')
    {
        $this->wx = ['openid' => $openid, 'session_key' => 'SESSION-KEY'] + ($unionid === '' ? [] : ['unionid' => $unionid]);

        return $this->api('POST', '/auth/login-wechat-mini', ['code' => 'CODE-'.$openid] + $extra, null, $headers + ['X-Source' => 'mp']);
    }

    public function test_it_is_off_until_the_mini_program_is_configured(): void
    {
        $this->assertFalse($this->api('GET', '/config/init')->json('data.wechat_login_enabled'));
        $this->login('o-1')->assertJsonPath('code', 1)->assertJsonPath('msg', '暂未开通微信登录');
        Http::assertNothingSent();

        $this->enable();
        $this->assertTrue($this->api('GET', '/config/init')->json('data.wechat_login_enabled'));
    }

    public function test_first_login_registers_a_member_and_later_logins_reuse_it(): void
    {
        $this->enable();

        $first = $this->login('o-first');
        $first->assertJsonPath('code', 0)->assertJsonPath('data.is_new', true);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'appid=wx1234567890abcdef') && str_contains($r->url(), 'js_code=CODE-o-first'));

        $member = Member::query()->findOrFail($first->json('data.info.id'));
        $this->assertStringStartsWith('wx_', $member->account);
        $this->assertStringStartsWith('微信用户', $member->nickname);
        $this->assertSame(['mp', 'zh-CN', ''], [$member->register_source, $member->lang, $member->password]);
        $this->assertNotNull($member->last_login_at);
        $bind = DB::table('member_auth')->where('member_id', $member->id)->first();
        $this->assertSame(['wechat_mini', 'wx1234567890abcdef', 'o-first'], [$bind->platform, $bind->app_id, $bind->openid]);

        // 发的令牌可以正常使用
        $this->api('GET', '/member/profile', [], $first->json('data.token'))->assertJsonPath('data.account', $member->account);

        // 同一个微信号再次登录：还是这个会员，不再注册
        $again = $this->login('o-first');
        $again->assertJsonPath('data.is_new', false)->assertJsonPath('data.info.id', $member->id);
        $this->assertSame(1, Member::query()->where('account', 'like', 'wx\_%')->count());
        $this->assertSame(1, DB::table('member_auth')->count());

        // 另一个微信号是另一个会员
        $this->assertNotSame($member->id, $this->login('o-second')->json('data.info.id'));

        // 微信的会话密钥用不到，哪里都不保存
        $this->assertStringNotContainsString('SESSION-KEY', json_encode(DB::table('member_auth')->get()).$first->getContent());
    }

    public function test_auto_registered_members_cannot_sign_in_with_a_password(): void
    {
        $this->enable();
        $account = Member::query()->findOrFail($this->login('o-nopwd')->json('data.info.id'))->account;

        foreach (['', ' ', 'wx', $account] as $guess) {
            $this->assertNotSame(0, $this->api('POST', '/auth/login', ['account' => $account, 'password' => $guess])->json('code'), "不应该能用密码 [{$guess}] 登录");
        }
    }

    public function test_invite_code_binds_the_referrer_only_on_first_login_and_a_bad_code_does_not_block(): void
    {
        $this->enable();
        [$parent] = $this->makeMember();
        $invite = DistributionService::inviteCode((int) $parent->id);

        $child = $this->login('o-child', ['invite_code' => $invite]);
        $this->assertSame((int) $parent->id, (int) Member::query()->find($child->json('data.info.id'))->parent_id);

        // 邀请码不对：照常登录，只是不绑定上级
        $loner = $this->login('o-loner', ['invite_code' => 'NOT-A-CODE']);
        $loner->assertJsonPath('code', 0);
        $this->assertSame(0, (int) Member::query()->find($loner->json('data.info.id'))->parent_id);

        // 已经注册过的人之后带着邀请码登录，不会被改绑
        $this->login('o-loner', ['invite_code' => $invite])->assertJsonPath('data.is_new', false);
        $this->assertSame(0, (int) Member::query()->find($loner->json('data.info.id'))->parent_id);
    }

    public function test_same_wechat_identity_from_another_wechat_app_joins_the_existing_member(): void
    {
        $this->enable();
        [$existing] = $this->makeMember();
        // 这个人以前在同一开放平台下的另一个微信应用里绑定过
        DB::table('member_auth')->insert(['member_id' => $existing->id, 'platform' => 'wechat_mp', 'app_id' => 'wxother', 'openid' => 'o-other', 'unionid' => 'U-1', 'created_at' => now()]);

        $r = $this->login('o-mini', [], [], 'U-1');
        $r->assertJsonPath('data.is_new', false)->assertJsonPath('data.info.id', $existing->id);
        $this->assertSame(2, DB::table('member_auth')->where('member_id', $existing->id)->count());

        // 之前没有 unionid 的绑定，之后微信给了就补上
        $plain = $this->login('o-plain')->json('data.info.id');
        $this->login('o-plain', [], [], 'U-2');
        $this->assertSame('U-2', DB::table('member_auth')->where('member_id', $plain)->value('unionid'));
    }

    public function test_disabled_and_deleted_members(): void
    {
        $this->enable();
        $id = $this->login('o-x')->json('data.info.id');

        Member::query()->whereKey($id)->update(['status' => 0]);
        $this->login('o-x')->assertJsonPath('code', 1)->assertJsonPath('msg', '账号已被禁用，请联系客服');

        // 会员注销后，同一个微信号再来就是一个新会员
        Member::query()->whereKey($id)->update(['status' => 1]);
        Member::query()->findOrFail($id)->delete();
        $new = $this->login('o-x');
        $new->assertJsonPath('data.is_new', true);
        $this->assertNotSame($id, $new->json('data.info.id'));
        $this->assertSame(1, DB::table('member_auth')->where('openid', 'o-x')->count());
    }

    public function test_wechat_errors_become_buyer_friendly_messages_in_the_buyers_language(): void
    {
        $this->enable();
        $try = function (array $wx, array $headers = []) {
            $this->wx = $wx;

            return $this->api('POST', '/auth/login-wechat-mini', ['code' => 'C'], null, $headers)->json('msg');
        };

        $this->assertSame('微信登录凭证已失效，请重试', $try(['errcode' => 40029, 'errmsg' => 'invalid code']));
        $this->assertSame('微信登录凭证已失效，请重试', $try(['errcode' => 40163, 'errmsg' => 'code been used']));
        // 配置错误等其他情况：详情只进日志，买家看到通用提示
        $this->assertSame('微信登录暂时不可用，请稍后再试', $try(['errcode' => 40125, 'errmsg' => 'invalid appsecret']));
        $this->assertSame('WeChat sign-in is temporarily unavailable. Please try again later.', $try(['errcode' => -1, 'errmsg' => 'system busy'], ['X-Lang' => 'en']));
        $this->assertSame(0, Member::query()->count());

        // 没带登录凭证
        $this->assertNotSame(0, $this->api('POST', '/auth/login-wechat-mini', [])->json('code'));
    }

    public function test_admin_can_configure_and_test_the_mini_program(): void
    {
        $list = $this->adminApi('GET', '/driver/app');
        $list->assertJsonPath('code', 0);
        $this->assertSame(['wechat_mini', false], [$list->json('data.0.driver'), $list->json('data.0.configured')]);

        // 没填完整不能启用
        $this->adminApi('PUT', '/driver/app/wechat_mini', ['is_enabled' => 1, 'config' => ['app_id' => 'wx1']])->assertJsonPath('code', 1);
        $this->adminApi('PUT', '/driver/app/wechat_mini', ['is_enabled' => 1, 'config' => ['app_id' => 'wx1', 'app_secret' => 'WRONG-SECRET']])->assertJsonPath('code', 0);

        // AppSecret 加密保存，列表里不回显
        $this->assertStringNotContainsString('WRONG-SECRET', (string) DB::table('driver_config')->where('type', 'app')->value('config'));
        $this->assertStringNotContainsString('WRONG-SECRET', $this->adminApi('GET', '/driver/app')->getContent());

        // 测试连接：密钥不对会把微信的说明带出来，改对后通过
        $bad = $this->adminApi('POST', '/driver/app/wechat_mini/test');
        $bad->assertJsonPath('code', 1);
        $this->assertStringContainsString('invalid appsecret', (string) $bad->json('msg'));
        $this->adminApi('PUT', '/driver/app/wechat_mini', ['is_enabled' => 1, 'config' => ['app_id' => 'wx1', 'app_secret' => 'APP-SECRET']])->assertJsonPath('code', 0);
        $this->adminApi('POST', '/driver/app/wechat_mini/test')->assertJsonPath('code', 0);
    }
}
