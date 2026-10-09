<?php

namespace App\Services\Api;

use App\Models\Member;
use App\Models\MemberToken;
use App\Services\Common\LocaleService;
use App\Services\Common\SmsCodeService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 会员注册 / 登录 / 令牌。
 * 手机号注册：后台启用短信驱动后必须带短信验证码（只支持中国大陆手机号，境外用户用邮箱注册）；
 * 未启用短信时不校验。邮箱注册目前没有邮箱验证码。
 */
class MemberAuthService
{
    public function __construct(private LocaleService $locale, private SmsCodeService $sms) {}

    /** @param array{account:string,password:string,nickname?:string,phone_area?:string,code?:string,lang?:string,currency?:string} $d */
    public function register(array $d, string $source, string $ip, string $ua): array
    {
        $account = trim($d['account']);
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
                'level_id'        => 1,
                'status'          => 1,
            ]);
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

    /** 短信找回密码：重置后所有设备的登录全部失效 */
    public function resetPasswordBySms(string $mobile, string $code, string $password): void
    {
        $member = $this->findByMobile($mobile) ?? throw new BusinessException('该手机号尚未注册');
        $this->sms->verify('reset', (string) $member->phone_area, $mobile, $code);

        $member->forceFill(['password' => Hash::make($password)])->save();
        MemberToken::query()->where('member_id', $member->id)->delete();
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
            'points' => (int) $m->points, 'balance' => (string) $m->balance,
            'lang' => $m->lang, 'currency' => $m->currency,
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

    public function changePassword(Member $m, string $old, string $new, string $currentPlain): void
    {
        if (!Hash::check($old, $m->password)) {
            throw new BusinessException('原密码不正确');
        }
        if (Hash::check($new, $m->password)) {
            throw new BusinessException('新密码不能与原密码相同');
        }
        $m->forceFill(['password' => Hash::make($new)])->save();

        // 其它设备登录全部失效，当前设备保留
        MemberToken::query()->where('member_id', $m->id)->where('token_hash', '!=', hash('sha256', $currentPlain))->delete();
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
