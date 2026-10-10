<?php

namespace App\Services\Api;

use App\Drivers\DriverManager;
use App\Models\Member;
use App\Models\MemberToken;
use App\Services\Common\DistributionService;
use App\Services\Common\EmailCodeService;
use App\Services\Common\LocaleService;
use App\Services\Common\MemberGrowthService;
use App\Services\Common\PointRewardService;
use App\Services\Common\SmsCodeService;
use App\Services\Common\UploadService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 会员注册 / 登录 / 令牌。
 * 手机号注册：后台启用短信驱动后必须带短信验证码（只支持中国大陆手机号，境外用户用邮箱注册）；
 * 未启用短信时不校验。
 * 邮箱注册：基础设置里开启“邮箱注册需要验证码”后必须带邮箱验证码；未开启时不校验。
 * 微信小程序登录：后台“应用 → 小程序”启用后可用，绑定关系存 gm_member_auth（platform = wechat_mini）。
 * 同意协议：后台填了用户协议或隐私政策时，注册（含微信一键登录的首次登录）必须带 agree，同意的时间记在 agreed_at。
 */
class MemberAuthService
{
    public function __construct(private LocaleService $locale, private SmsCodeService $sms, private EmailCodeService $emailCodes,
        private DistributionService $distribution, private UploadService $uploads, private DriverManager $drivers,
        private MemberGrowthService $growth, private PointRewardService $rewards, private MemberPrivacyService $privacy) {}

    /** @param array{account:string,password:string,nickname?:string,phone_area?:string,code?:string,invite_code?:string,lang?:string,currency?:string,agree?:mixed} $d */
    public function register(array $d, string $source, string $ip, string $ua): array
    {
        $account = trim($d['account']);
        $agreedAt = $this->privacy->agreedAt($d['agree'] ?? null, (string) ($d['lang'] ?? ''));
        $isEmail = str_contains($account, '@');
        $isPhone = !$isEmail && preg_match('/^\d{6,15}$/', $account) === 1;

        // 同一账号并发注册：用缓存锁串行化（account 列没有唯一索引，因为软删除后允许重新注册）
        $lock = Cache::lock('member_register:' . strtolower($account), 10);
        if (!$lock->get()) {
            throw new BusinessException('操作过于频繁，请稍后再试');
        }
        try {
            $exists = Member::query()->where(function ($q) use ($account) {
                $q->where('account', $account)->orWhere('email', $account)->orWhere('phone', $account);
            })->exists();
            if ($exists) {
                throw new BusinessException('该账号已注册');
            }
            if ($isPhone && $this->sms->enabled()) {
                $area = (string) ($d['phone_area'] ?? '86');
                if (!SmsCodeService::isCnMobile($area, $account)) {
                    throw new BusinessException('目前只支持中国大陆手机号注册，其他地区请使用邮箱注册');
                }
                if (trim((string) ($d['code'] ?? '')) === '') {
                    throw new BusinessException('请输入短信验证码');
                }
                $this->sms->verify('register', $area, $account, (string) $d['code']);
            }
            if ($isEmail && $this->emailCodes->enabled()) {
                if (trim((string) ($d['code'] ?? '')) === '') {
                    throw new BusinessException('请输入邮箱验证码');
                }
                $this->emailCodes->verify('register', $account, (string) $d['code']);
            }

            // 邀请码：填了就必须有效，绑定后成为推荐人的下级（分销关系）
            $parentId = trim((string) ($d['invite_code'] ?? '')) !== '' ? $this->distribution->parentIdFromInvite((string) $d['invite_code']) : 0;

            $lang = in_array($d['lang'] ?? '', $this->locale->codes(), true) ? $d['lang'] : $this->locale->defaultLang();
            $currency = in_array($d['currency'] ?? '', $this->locale->currencyCodes(), true) ? $d['currency'] : $this->locale->baseCurrency();

            $member = Member::query()->create([
                'account'         => $account,
                'email'           => $isEmail ? $account : '',
                'phone_area'      => $isPhone ? preg_replace('/\D/', '', (string) ($d['phone_area'] ?? '86')) ?: '86' : '86',
                'phone'           => $isPhone ? $account : '',
                'password'        => Hash::make($d['password']),
                'nickname'        => mb_substr(trim((string) ($d['nickname'] ?? '')) ?: $this->defaultNickname($account), 0, 60),
                'lang'            => $lang,
                'currency'        => $currency,
                'register_source' => $source,
                'parent_id'       => $parentId,
                'level_id'        => 1,
                'status'          => 1,
                'agreed_at'       => $agreedAt,
            ]);
            $this->rewards->onRegister($member);
        } finally {
            $lock->release();
        }

        return $this->issue($member, $source, $ip, $ua);
    }

    /** 获取短信验证码。注册要求号码未注册；登录、找回密码要求号码已注册 */
    public function sendSmsCode(string $purpose, string $phoneArea, string $mobile, string $ip): void
    {
        $member = $this->findByMobile($mobile);
        if ($purpose === 'register' && $member) {
            throw new BusinessException('该手机号已注册，请直接登录');
        }
        if ($purpose !== 'register' && !$member) {
            throw new BusinessException('该手机号尚未注册');
        }
        $this->sms->send($purpose, $phoneArea, $mobile, $ip);
    }

    public function loginBySms(string $mobile, string $code, string $source, string $ip, string $ua): array
    {
        $member = $this->findByMobile($mobile) ?? throw new BusinessException('该手机号尚未注册');
        if ((int) $member->status !== 1) {
            throw new BusinessException('账号已被禁用，请联系客服');
        }
        $this->sms->verify('login', (string) $member->phone_area, $mobile, $code);

        $member->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->save();

        return $this->issue($member, $source, $ip, $ua);
    }

    /**
     * 微信小程序一键登录。这个微信号登录过就直接登录；没登录过就自动注册一个会员并绑定。
     * 自动注册的会员没有密码（账号是随机的 wx_ 开头），只能用微信登录。
     * 第一次登录（要新建会员）时同样要同意协议；已经注册过的直接登录，不看 agree。
     * @param array{invite_code?:string,lang?:string,currency?:string,agree?:mixed} $d
     */
    public function loginByWechatMini(string $code, array $d, string $source, string $ip, string $ua): array
    {
        $driver = $this->drivers->wechatMini() ?? throw new BusinessException('暂未开通微信登录');
        $session = $driver->session($code);
        $appId = $driver->appId();

        // 同一个微信号并发登录：串行化，避免第一次登录时注册出两个会员
        $lock = Cache::lock('member_wechat_login:' . sha1($appId . '|' . $session['openid']), 10);
        if (!$lock->get()) {
            throw new BusinessException('操作过于频繁，请稍后再试');
        }
        try {
            [$member, $isNew] = DB::transaction(fn () => $this->memberForWechat('wechat_mini', $appId, $session['openid'], $session['unionid'], $d, $source));
        } finally {
            $lock->release();
        }
        if ((int) $member->status !== 1) {
            throw new BusinessException('账号已被禁用，请联系客服');
        }
        if ($isNew) {
            $this->rewards->onRegister($member);
        }
        $member->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->save();

        return $this->issue($member, $source, $ip, $ua) + ['is_new' => $isNew];
    }

    /**
     * 找到这个微信身份对应的会员，没有就新建。
     * 顺序：这个应用下的 openid → 同一个 unionid 在其他微信应用下绑定过的会员 → 新建。
     * @return array{0:Member,1:bool} 会员、是否新注册
     */
    private function memberForWechat(string $platform, string $appId, string $openid, string $unionid, array $d, string $source): array
    {
        $bind = DB::table('member_auth')->where('platform', $platform)->where('app_id', $appId)->where('openid', $openid)->first();
        if ($bind) {
            if ($member = Member::query()->find($bind->member_id)) {
                if ($unionid !== '' && (string) $bind->unionid === '') {
                    DB::table('member_auth')->where('id', $bind->id)->update(['unionid' => $unionid]);
                }

                return [$member, false];
            }
            // 原来绑定的会员已经注销：解除旧绑定，当成新用户
            DB::table('member_auth')->where('id', $bind->id)->delete();
        }

        $member = null;
        if ($unionid !== '') {
            $ids = DB::table('member_auth')->where('unionid', $unionid)->where('platform', 'like', 'wechat%')->orderBy('id')->pluck('member_id');
            $member = $ids->isEmpty() ? null : Member::query()->whereIn('id', $ids)->orderBy('id')->first();
        }
        $isNew = $member === null;
        if ($isNew) {
            // 邀请码只在注册时生效；一键登录是静默的，邀请码无效时不拦登录，只是不绑定
            $parentId = 0;
            if (trim((string) ($d['invite_code'] ?? '')) !== '') {
                try {
                    $parentId = $this->distribution->parentIdFromInvite((string) $d['invite_code']);
                } catch (BusinessException $e) {
                    $parentId = 0;
                }
            }
            $lang = in_array($d['lang'] ?? '', $this->locale->codes(), true) ? $d['lang'] : $this->locale->defaultLang();
            $agreedAt = $this->privacy->agreedAt($d['agree'] ?? null, $lang);
            $member = Member::query()->create([
                'agreed_at'       => $agreedAt,
                'account'         => 'wx_' . Str::lower(Str::random(12)),
                'password'        => '',
                'nickname'        => (str_starts_with($lang, 'zh') ? '微信用户' : 'WeChat User ') . Str::lower(Str::random(4)),
                'lang'            => $lang,
                'currency'        => in_array($d['currency'] ?? '', $this->locale->currencyCodes(), true) ? $d['currency'] : $this->locale->baseCurrency(),
                'register_source' => $source,
                'parent_id'       => $parentId,
                'level_id'        => 1,
                'status'          => 1,
            ]);
        }
        DB::table('member_auth')->insert([
            'member_id' => $member->id, 'platform' => $platform, 'app_id' => $appId, 'openid' => $openid, 'unionid' => $unionid, 'created_at' => now(),
        ]);

        return [$member, $isNew];
    }

    /** 短信找回密码：重置后所有设备的登录全部失效 */
    public function resetPasswordBySms(string $mobile, string $code, string $password): void
    {
        $member = $this->findByMobile($mobile) ?? throw new BusinessException('该手机号尚未注册');
        $this->sms->verify('reset', (string) $member->phone_area, $mobile, $code);

        $member->forceFill(['password' => Hash::make($password)])->save();
        MemberToken::query()->where('member_id', $member->id)->delete();
    }

    /** 获取邮箱验证码。注册要求邮箱未注册；找回密码要求邮箱已注册 */
    public function sendEmailCode(string $purpose, string $email, string $ip, string $lang): void
    {
        $member = $this->findByEmail($email);
        if ($purpose === 'register' && $member) {
            throw new BusinessException('该邮箱已注册，请直接登录');
        }
        if ($purpose !== 'register' && !$member) {
            throw new BusinessException('该邮箱尚未注册');
        }
        $this->emailCodes->send($purpose, $email, $ip, $lang);
    }

    /** 邮箱找回密码：重置后所有设备的登录全部失效 */
    public function resetPasswordByEmail(string $email, string $code, string $password): void
    {
        $member = $this->findByEmail($email) ?? throw new BusinessException('该邮箱尚未注册');
        $this->emailCodes->verify('reset', $email, $code);

        $member->forceFill(['password' => Hash::make($password)])->save();
        MemberToken::query()->where('member_id', $member->id)->delete();
    }

    private function findByEmail(string $email): ?Member
    {
        $email = trim($email);

        return Member::query()->where(fn ($q) => $q->where('email', $email)->orWhere('account', $email))->first();
    }

    private function findByMobile(string $mobile): ?Member
    {
        $mobile = trim($mobile);

        return Member::query()->where(fn ($q) => $q->where('phone', $mobile)->orWhere('account', $mobile))->first();
    }

    public function login(string $account, string $password, string $source, string $ip, string $ua): array
    {
        $account = trim($account);
        $failKey = 'member_login_fail:' . strtolower($account) . '|' . $ip;
        $lockMinutes = 15;

        if ((int) Cache::get($failKey, 0) >= 5) {
            throw new BusinessException("登录失败次数过多，请 {$lockMinutes} 分钟后再试", 429);
        }

        $member = Member::query()->where(function ($q) use ($account) {
            $q->where('account', $account)->orWhere('email', $account)->orWhere('phone', $account);
        })->first();

        if (!$member || !Hash::check($password, $member->password)) {
            Cache::add($failKey, 0, now()->addMinutes($lockMinutes));
            Cache::increment($failKey);
            throw new BusinessException('账号或密码错误');
        }
        if ((int) $member->status !== 1) {
            throw new BusinessException('账号已被禁用，请联系客服');
        }
        Cache::forget($failKey);

        $member->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->save();

        return $this->issue($member, $source, $ip, $ua);
    }

    /** 校验令牌，成功返回会员并顺延有效期 */
    public function authenticate(string $plain): ?Member
    {
        $token = MemberToken::query()->where('token_hash', hash('sha256', $plain))->where('expires_at', '>', now())->first();
        if (!$token) {
            return null;
        }
        $member = Member::query()->find($token->member_id);
        if (!$member || (int) $member->status !== 1) {
            return null;
        }
        if (!$token->last_used_at || $token->last_used_at->lt(now()->subMinutes(10))) {
            $token->forceFill([
                'last_used_at' => now(),
                'expires_at'   => now()->addDays($this->ttlDays()),
            ])->save();
        }

        return $member;
    }

    public function logout(string $plain): void
    {
        MemberToken::query()->where('token_hash', hash('sha256', $plain))->delete();
    }

    public function profile(Member $m): array
    {
        $level = DB::table('member_level')->where('id', $m->level_id)->first(['id', 'discount']);

        return [
            'id' => (int) $m->id, 'account' => $m->account, 'nickname' => $m->nickname, 'avatar' => $m->avatar,
            'gender' => (int) $m->gender, 'birthday' => $m->birthday?->format('Y-m-d'),
            'phone_area' => $m->phone_area, 'phone' => $m->phone, 'email' => $m->email,
            'level_id' => (int) $m->level_id, 'level_discount' => $level ? (string) $level->discount : '100',
            // level_name 等级名称（按会员的语言）、growth 成长值、next_level 下一级的名称 / 门槛 / 还差多少（已是最高级时为 null）
            ...$this->growth->summary($m, (string) $m->lang),
            'points' => (int) $m->points, 'balance' => (string) $m->balance,
            'lang' => $m->lang, 'currency' => $m->currency,
            // 微信一键登录注册的会员一开始没有密码
            'has_password' => (string) $m->password !== '',
        ];
    }

    /** @param array{nickname?:string,avatar?:string,gender?:int,birthday?:?string,lang?:string,currency?:string} $d */
    public function updateProfile(Member $m, array $d): array
    {
        $upd = [];
        foreach (['nickname', 'avatar', 'gender', 'birthday'] as $f) {
            if (array_key_exists($f, $d)) {
                $upd[$f] = $d[$f] === '' ? null : $d[$f];
            }
        }
        if (array_key_exists('nickname', $upd)) {
            $upd['nickname'] = (string) $upd['nickname'];
        }
        if (array_key_exists('avatar', $upd)) {
            $upd['avatar'] = (string) $upd['avatar'];
            // 换头像只接受本人通过上传接口传的图片；原样提交现有头像（比如第三方登录带来的）不拦
            if ($upd['avatar'] !== '' && $upd['avatar'] !== (string) $m->avatar) {
                $this->uploads->assertMemberImages((int) $m->id, [$upd['avatar']]);
            }
        }
        if (isset($d['lang'])) {
            if (!in_array($d['lang'], $this->locale->codes(), true)) {
                throw new BusinessException('不支持的语言');
            }
            $upd['lang'] = $d['lang'];
        }
        if (isset($d['currency'])) {
            if (!in_array($d['currency'], $this->locale->currencyCodes(), true)) {
                throw new BusinessException('不支持的币种');
            }
            $upd['currency'] = $d['currency'];
        }
        if ($upd !== []) {
            $m->forceFill($upd)->save();
        }

        return $this->profile($m->refresh());
    }

    /** 修改密码；还没有密码的会员（微信一键登录注册的）是第一次设置，不用原密码 */
    public function changePassword(Member $m, string $old, string $new, string $currentPlain): void
    {
        if ((string) $m->password !== '') {
            if ($old === '') {
                throw new BusinessException('请输入原密码');
            }
            if (!Hash::check($old, $m->password)) {
                throw new BusinessException('原密码不正确');
            }
            if (Hash::check($new, $m->password)) {
                throw new BusinessException('新密码不能与原密码相同');
            }
        }
        $m->forceFill(['password' => Hash::make($new)])->save();

        // 其它设备登录全部失效，当前设备保留
        MemberToken::query()->where('member_id', $m->id)->where('token_hash', '!=', hash('sha256', $currentPlain))->delete();
    }

    /**
     * 绑定邮箱或手机号：绑定后可以用它登录、找回密码。每种只能绑一次，换绑要找客服（后台改）。
     * 验证规则和注册一致：开了邮箱验证 / 启用了短信就必须带验证码（客户端以 register 用途获取），没开就不校验。
     * @param array{type:string,email?:?string,mobile?:?string,phone_area?:?string,code?:?string} $d
     */
    public function bindContact(Member $m, array $d): array
    {
        $isEmail = $d['type'] === 'email';
        $value = trim((string) ($isEmail ? ($d['email'] ?? '') : ($d['mobile'] ?? '')));
        if ((string) ($isEmail ? $m->email : $m->phone) !== '') {
            throw new BusinessException($isEmail ? '已绑定邮箱，如需更换请联系客服' : '已绑定手机号，如需更换请联系客服');
        }

        // 和注册用同一把锁：同一个邮箱 / 手机号不会同时被注册和绑定
        $lock = Cache::lock('member_register:' . strtolower($value), 10);
        if (!$lock->get()) {
            throw new BusinessException('操作过于频繁，请稍后再试');
        }
        try {
            $taken = Member::query()->where('id', '!=', $m->id)->where(function ($q) use ($value) {
                $q->where('account', $value)->orWhere('email', $value)->orWhere('phone', $value);
            })->exists();
            if ($taken) {
                throw new BusinessException($isEmail ? '该邮箱已被其他账号使用' : '该手机号已被其他账号使用');
            }
            $code = trim((string) ($d['code'] ?? ''));
            if ($isEmail) {
                if ($this->emailCodes->enabled()) {
                    if ($code === '') {
                        throw new BusinessException('请输入邮箱验证码');
                    }
                    $this->emailCodes->verify('register', $value, $code);
                }
                $m->forceFill(['email' => $value])->save();
            } else {
                $area = preg_replace('/\D/', '', (string) ($d['phone_area'] ?? '86')) ?: '86';
                if ($this->sms->enabled()) {
                    if (!SmsCodeService::isCnMobile($area, $value)) {
                        throw new BusinessException('目前只支持绑定中国大陆手机号');
                    }
                    if ($code === '') {
                        throw new BusinessException('请输入短信验证码');
                    }
                    $this->sms->verify('register', $area, $value, $code);
                }
                $m->forceFill(['phone_area' => $area, 'phone' => $value])->save();
            }
        } finally {
            $lock->release();
        }

        return $this->profile($m->refresh());
    }

    private function issue(Member $m, string $source, string $ip, string $ua): array
    {
        MemberToken::query()->where('expires_at', '<', now())->limit(500)->delete();

        // 单会员最多保留 10 个有效登录，超出则踢掉最早的
        $keep = MemberToken::query()->where('member_id', $m->id)->orderByDesc('id')->limit(9)->pluck('id');
        MemberToken::query()->where('member_id', $m->id)->whereNotIn('id', $keep)->delete();

        $plain = Str::random(64);
        $expires = now()->addDays($this->ttlDays());
        MemberToken::query()->create([
            'member_id' => $m->id, 'token_hash' => hash('sha256', $plain), 'platform' => $source,
            'expires_at' => $expires, 'last_used_at' => now(), 'ip' => $ip, 'user_agent' => Str::limit($ua, 250, ''),
        ]);

        return ['token' => $plain, 'expires_at' => $expires->format('Y-m-d H:i:s'), 'info' => $this->profile($m)];
    }

    private function ttlDays(): int
    {
        return max(1, (int) config('mall.member_token_days', 30));
    }

    private function defaultNickname(string $account): string
    {
        $base = str_contains($account, '@') ? strstr($account, '@', true) : $account;

        return mb_substr((string) $base, 0, 3) . '***' . Str::lower(Str::random(3));
    }
}
