<?php

namespace Tests\Feature;

use App\Models\DriverConfig;
use App\Models\Member;
use App\Services\Common\ConfigService;
use App\Services\Common\DistributionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/** Google 登录：凭证验证、自动注册、再次登录、邮箱已被占用、邀请码、同意协议、后台配置（Google 一侧是模拟的：现场生成一对密钥来签发凭证） */
class GoogleLoginTest extends FeatureTestCase
{
    private const CLIENT_ID = '1234567890-abcdefg.apps.googleusercontent.com';

    /** @var array<string,\OpenSSLAsymmetricKey> kid => 私钥 */
    private array $keys = [];

    /** 假的 Google 公钥接口现在给出哪些 kid */
    private array $published = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['kid-1', 'kid-2', 'kid-other'] as $kid) {
            // tests/Fixtures/google 里的密钥是随手生成、仅供测试的，和 Google 以及任何真实应用无关
            $this->keys[$kid] = openssl_pkey_get_private((string) file_get_contents(base_path("tests/Fixtures/google/{$kid}.pem")));
        }
        $this->published = ['kid-1'];
        Http::preventStrayRequests();
        Http::fake(['www.googleapis.com/oauth2/v3/certs' => fn () => Http::response(['keys' => array_map(fn ($kid) => $this->jwk($kid), $this->published)])]);
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function jwk(string $kid): array
    {
        $d = openssl_pkey_get_details($this->keys[$kid])['rsa'];

        return ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => self::b64($d['n']), 'e' => self::b64($d['e'])];
    }

    /** 签发一个身份凭证；$claims 覆盖默认内容，$signWith 指定用哪把私钥签（默认和 kid 一致） */
    private function token(array $claims = [], string $kid = 'kid-1', ?string $signWith = null, string $alg = 'RS256'): string
    {
        $claims += [
            'iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'sub' => '10001', 'email' => 'Buyer@Gmail.com', 'email_verified' => true,
            'name' => 'Ada Buyer', 'iat' => time() - 5, 'exp' => time() + 3600,
        ];
        $input = self::b64(json_encode(['alg' => $alg, 'kid' => $kid, 'typ' => 'JWT'])).'.'.self::b64(json_encode($claims));
        openssl_sign($input, $signature, $this->keys[$signWith ?? $kid], OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64($signature);
    }

    private function enable(array $config = []): void
    {
        DriverConfig::query()->create(['type' => 'oauth', 'driver' => 'google', 'name' => 'Google 登录', 'is_enabled' => 1, 'is_default' => 0, 'sort' => 0,
            'config' => $config + ['client_id' => self::CLIENT_ID]]);
    }

    private function login(string $credential, array $extra = [], array $headers = [])
    {
        return $this->api('POST', '/auth/login-google', ['credential' => $credential] + $extra, null, $headers + ['X-Source' => 'pc']);
    }

    public function test_it_is_off_until_configured_and_the_client_id_is_published(): void
    {
        $this->assertSame('', $this->api('GET', '/config/init')->json('data.google_client_id'));
        $this->login($this->token())->assertJsonPath('code', 1)->assertJsonPath('msg', '暂未开通 Google 登录');
        Http::assertNothingSent();

        $this->enable();
        $this->assertSame(self::CLIENT_ID, $this->api('GET', '/config/init')->json('data.google_client_id'));
    }

    public function test_first_login_registers_a_member_and_later_logins_reuse_it(): void
    {
        $this->enable();

        $first = $this->login($this->token());
        $first->assertJsonPath('code', 0)->assertJsonPath('data.is_new', true);
        $member = Member::query()->where('email', 'buyer@gmail.com')->firstOrFail();
        $this->assertStringStartsWith('gg_', $member->account);
        $this->assertSame(['Ada Buyer', '', 'pc'], [$member->nickname, (string) $member->password, $member->register_source]);
        $bind = DB::table('member_auth')->where('member_id', $member->id)->first();
        $this->assertSame(['google', '', '10001'], [$bind->platform, $bind->app_id, $bind->openid]);

        // 拿到的令牌能用；资料里看得出还没有密码
        $this->api('GET', '/member/profile', [], $first->json('data.token'))->assertJsonPath('data.has_password', false)->assertJsonPath('data.email', 'buyer@gmail.com');

        // 再登录：同一个会员，不再新建；公钥有缓存，没有再去取
        $this->login($this->token(['name' => 'Renamed']))->assertJsonPath('code', 0)->assertJsonPath('data.is_new', false);
        $this->assertSame(1, Member::query()->where('account', 'like', 'gg\_%')->count());
        $this->assertSame('Ada Buyer', $member->fresh()->nickname);
        Http::assertSentCount(1);

        // 后台换了客户端 ID（凭证也是发给新 ID 的）：老会员照样登录，因为认的是 Google 账号本身
        DriverConfig::query()->where('driver', 'google')->first()->update(['config' => ['client_id' => '99-new.apps.googleusercontent.com']]);
        $this->login($this->token(['aud' => '99-new.apps.googleusercontent.com']))->assertJsonPath('data.is_new', false);
    }

    public function test_forged_or_stale_credentials_are_rejected(): void
    {
        $this->enable();
        $bad = fn (string $credential) => $this->login($credential)->assertJsonPath('code', 1)->assertJsonPath('msg', 'Google 登录凭证无效，请重试');

        $bad('not-a-token');
        $bad($this->token([], 'kid-1', 'kid-other'));                         // 签名不是 Google 的私钥签的
        $bad($this->token(['aud' => 'someone-else.apps.googleusercontent.com'])); // 发给别的网站的凭证
        $bad($this->token(['iss' => 'https://evil.example.com']));
        $bad($this->token(['exp' => time() - 600]));
        $bad($this->token(['sub' => '']));
        $bad($this->token([], 'kid-1', null, 'none'));
        // 改了内容但沿用原来的签名
        [$h, , $s] = explode('.', $this->token());
        $bad($h.'.'.self::b64(json_encode(['iss' => 'https://accounts.google.com', 'aud' => self::CLIENT_ID, 'sub' => '666', 'exp' => time() + 3600])).'.'.$s);
        $this->assertSame(0, Member::query()->count());

        // 英文买家看到英文；不带凭证是校验错误
        $this->login('x.y.z', [], ['X-Lang' => 'en'])->assertJsonPath('msg', 'Google sign-in failed. Please try again.');
        $this->api('POST', '/auth/login-google', [])->assertJsonPath('code', 422);
    }

    public function test_rotated_keys_are_fetched_again_and_outage_is_reported(): void
    {
        $this->enable();
        $this->login($this->token())->assertJsonPath('code', 0);

        // Google 换了密钥：缓存里没有这个 kid，重新取一次
        $this->published = ['kid-2'];
        $this->login($this->token(['sub' => '10002', 'email' => 'b@gmail.com'], 'kid-2'))->assertJsonPath('code', 0);
        Http::assertSentCount(2);

        // 公钥取不到（服务器连不上 Google）
        Cache::flush();
        $this->published = [];
        $this->login($this->token())->assertJsonPath('code', 1)->assertJsonPath('msg', 'Google 登录暂时不可用，请稍后再试');
    }

    public function test_email_already_used_by_another_member_is_not_merged(): void
    {
        $this->enable();
        [$existing] = $this->makeMember();
        $existing->forceFill(['email' => 'buyer@gmail.com'])->save();

        $this->login($this->token())->assertJsonPath('code', 1)->assertJsonPath('msg', '这个 Google 账号的邮箱已经注册过。请先用邮箱和密码登录，再到“账号与安全”里绑定 Google');
        $this->assertSame(0, DB::table('member_auth')->count());

        // Google 没确认过的邮箱不采用：照常注册，只是会员没有邮箱
        $r = $this->login($this->token(['sub' => '20002', 'email_verified' => false]));
        $r->assertJsonPath('code', 0)->assertJsonPath('data.is_new', true);
        $this->assertSame('', (string) Member::query()->where('account', 'like', 'gg\_%')->firstOrFail()->email);

        // 没有名字时给一个默认昵称
        $this->login($this->token(['sub' => '20003', 'email' => 'c@gmail.com', 'name' => '']))->assertJsonPath('code', 0);
        $this->assertStringStartsWith('Google User ', Member::query()->where('email', 'c@gmail.com')->firstOrFail()->nickname);
    }

    public function test_invite_code_agreement_disabled_and_cancelled_members(): void
    {
        $this->enable();
        [$parent] = $this->makeMember();
        $invite = app(DistributionService::class)->inviteCode((int) $parent->id);

        // 邀请码只在新注册时生效；无效的邀请码不拦登录
        $this->login($this->token(), ['invite_code' => $invite])->assertJsonPath('code', 0);
        $member = Member::query()->where('email', 'buyer@gmail.com')->firstOrFail();
        $this->assertSame((int) $parent->id, (int) $member->parent_id);
        $this->login($this->token(['sub' => '30002', 'email' => 'd@gmail.com']), ['invite_code' => 'BAD-CODE'])->assertJsonPath('code', 0);
        $this->assertSame(0, (int) Member::query()->where('email', 'd@gmail.com')->firstOrFail()->parent_id);

        // 被禁用的会员登录不了
        $member->forceFill(['status' => 0])->save();
        $this->login($this->token())->assertJsonPath('code', 1)->assertJsonPath('msg', '账号已被禁用，请联系客服');

        // 会员注销后，同一个 Google 账号再来算新会员
        $member->forceFill(['status' => 1, 'email' => ''])->save();
        $member->delete();
        $again = $this->login($this->token());
        $again->assertJsonPath('code', 0)->assertJsonPath('data.is_new', true);
        $this->assertSame(1, DB::table('member_auth')->where('openid', '10001')->count());

        // 填了协议之后，第一次登录必须同意；已经注册过的不看
        app(ConfigService::class)->save('protocol', ['user_agreement' => ['zh-CN' => '<p>协议</p>']]);
        $this->login($this->token(['sub' => '30003', 'email' => 'e@gmail.com']))->assertJsonPath('code', 428)->assertJsonPath('msg', '请先阅读并同意用户协议和隐私政策');
        $this->login($this->token(['sub' => '30003', 'email' => 'e@gmail.com']), ['agree' => 1])->assertJsonPath('code', 0);
        $this->assertNotNull(Member::query()->where('email', 'e@gmail.com')->firstOrFail()->agreed_at);
        $this->login($this->token())->assertJsonPath('code', 0);
    }

    public function test_member_can_link_and_unlink_a_google_account(): void
    {
        $this->enable();
        [$member, $token] = $this->makeMember();
        $member->forceFill(['email' => 'buyer@gmail.com'])->save();
        $this->api('GET', '/member/profile', [], $token)->assertJsonPath('data.google_bound', false);

        // 邮箱被占用时直接用 Google 登录会被拒；登录后绑定就可以了，之后能用 Google 登录到这个会员
        $this->login($this->token())->assertJsonPath('code', 1);
        $this->api('POST', '/member/google', ['credential' => 'x.y.z'], $token)->assertJsonPath('code', 1)->assertJsonPath('msg', 'Google 登录凭证无效，请重试');
        $this->api('POST', '/member/google', ['credential' => $this->token()], $token)->assertJsonPath('code', 0)->assertJsonPath('data.google_bound', true);
        $in = $this->login($this->token());
        $in->assertJsonPath('code', 0)->assertJsonPath('data.is_new', false);
        $this->assertSame((int) $member->id, (int) $in->json('data.info.id'));

        // 一个会员只能绑一个；一个 Google 账号只能绑一个会员
        $this->api('POST', '/member/google', ['credential' => $this->token(['sub' => '40002'])], $token)
            ->assertJsonPath('code', 1)->assertJsonPath('msg', '已经绑定了 Google 账号，如需更换请先解绑');
        [, $otherToken] = $this->makeMember();
        $this->api('POST', '/member/google', ['credential' => $this->token()], $otherToken)
            ->assertJsonPath('code', 1)->assertJsonPath('msg', '这个 Google 账号已经绑定了别的会员');
        $this->assertSame(1, DB::table('member_auth')->count());

        // 解绑：有密码才行；解绑后这个 Google 账号可以绑到别的会员上
        $this->api('DELETE', '/member/google', [], $token)->assertJsonPath('code', 0)->assertJsonPath('data.google_bound', false);
        $this->api('POST', '/member/google', ['credential' => $this->token()], $otherToken)->assertJsonPath('code', 0);

        // Google 登录注册的会员没有密码，不能解绑（解了就登录不了了）；设了密码之后可以
        $g = $this->login($this->token(['sub' => '40003', 'email' => 'g@gmail.com']))->json('data.token');
        $this->api('DELETE', '/member/google', [], $g)->assertJsonPath('code', 1)->assertJsonPath('msg', '请先设置登录密码，再解绑 Google 账号');
        $this->api('PUT', '/member/password', ['password' => 'secret123'], $g)->assertJsonPath('code', 0);
        $g = $this->api('POST', '/auth/login', ['account' => 'g@gmail.com', 'password' => 'secret123'])->json('data.token');
        $this->api('DELETE', '/member/google', [], $g)->assertJsonPath('code', 0);

        // 没登录不能绑定；没启用时也不能
        $this->api('POST', '/member/google', ['credential' => $this->token()])->assertJsonPath('code', 401);
        DriverConfig::query()->where('driver', 'google')->update(['is_enabled' => 0]);
        $this->api('POST', '/member/google', ['credential' => $this->token(['sub' => '40009'])], $token)->assertJsonPath('msg', '暂未开通 Google 登录');
    }

    public function test_admin_can_configure_and_test_the_connection(): void
    {
        $list = $this->adminApi('GET', '/driver/oauth')->json('data');
        $this->assertSame(['google'], array_column($list, 'driver'));

        // 没填客户端 ID 不能启用
        $this->adminApi('PUT', '/driver/oauth/google', ['is_enabled' => 1, 'config' => []])->assertJsonPath('code', 1);
        $this->adminApi('PUT', '/driver/oauth/google', ['is_enabled' => 1, 'config' => ['client_id' => 'not-a-client-id']])->assertJsonPath('code', 0);
        $this->assertStringContainsString('格式不对', $this->adminApi('POST', '/driver/oauth/google/test')->json('msg'));

        $this->adminApi('PUT', '/driver/oauth/google', ['is_enabled' => 1, 'config' => ['client_id' => self::CLIENT_ID]])->assertJsonPath('code', 0);
        $this->adminApi('POST', '/driver/oauth/google/test')->assertJsonPath('code', 0);

        // 服务器取不到公钥时，测试连接说清楚原因
        $this->published = [];
        $this->assertStringContainsString('取不到 Google 的公钥', $this->adminApi('POST', '/driver/oauth/google/test')->json('msg'));
    }
}
