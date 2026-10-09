<?php

namespace App\Services\Common;

use App\Drivers\DriverManager;
use App\Models\DriverConfig;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Cache;

/**
 * 短信验证码（注册 / 短信登录 / 找回密码共用）。验证码由短信平台生成和校验，这里只做：
 * - 号码规范：目前只支持中国大陆手机号（区号 86，11 位）
 * - 本地限流：同一号码 60 秒一次、每天最多 10 次；同一 IP 每小时最多 20 次
 * - 校验防爆破：同一号码同一用途 10 分钟内最多错 5 次
 */
class SmsCodeService
{
    public const PURPOSES = ['register', 'login', 'reset'];

    private const COOLDOWN = 60;
    private const DAILY_PER_MOBILE = 10;
    private const HOURLY_PER_IP = 20;
    private const MAX_WRONG = 5;

    public function __construct(private DriverManager $drivers) {}

    /** 后台是否启用了短信驱动 */
    public function enabled(): bool
    {
        return DriverConfig::query()->where('type', 'sms')->where('is_enabled', 1)->exists();
    }

    public function send(string $purpose, string $phoneArea, string $mobile, string $ip): void
    {
        $mobile = $this->normalize($purpose, $phoneArea, $mobile);

        $coolKey = "sms:cool:{$mobile}";
        $dayKey = "sms:day:{$mobile}:" . date('Ymd');
        $ipKey = "sms:ip:{$ip}:" . date('YmdH');
        if (Cache::has($coolKey)) {
            throw new BusinessException('验证码发送过于频繁，请 1 分钟后再试');
        }
        if ((int) Cache::get($dayKey, 0) >= self::DAILY_PER_MOBILE) {
            throw new BusinessException('该手机号今天获取验证码的次数已达上限');
        }
        if ((int) Cache::get($ipKey, 0) >= self::HOURLY_PER_IP) {
            throw new BusinessException('操作过于频繁，请稍后再试');
        }

        // 先占住冷却，避免并发请求重复发送；发送失败再释放
        Cache::put($coolKey, 1, self::COOLDOWN);
        try {
            $this->drivers->sms()->sendCode($purpose, $mobile);
        } catch (\Throwable $e) {
            Cache::forget($coolKey);
            throw $e;
        }

        Cache::add($dayKey, 0, 86400);
        Cache::increment($dayKey);
        Cache::add($ipKey, 0, 3600);
        Cache::increment($ipKey);
        Cache::forget($this->wrongKey($purpose, $mobile));
    }

    /** 校验验证码，不通过直接抛 BusinessException */
    public function verify(string $purpose, string $phoneArea, string $mobile, string $code): void
    {
        $mobile = $this->normalize($purpose, $phoneArea, $mobile);
        $code = trim($code);
        $wrongKey = $this->wrongKey($purpose, $mobile);

        if ((int) Cache::get($wrongKey, 0) >= self::MAX_WRONG) {
            throw new BusinessException('验证码错误次数过多，请重新获取验证码');
        }
        if (!preg_match('/^\d{4,8}$/', $code) || !$this->drivers->sms()->checkCode($purpose, $mobile, $code)) {
            Cache::add($wrongKey, 0, 600);
            Cache::increment($wrongKey);
            throw new BusinessException('验证码错误或已过期');
        }
        Cache::forget($wrongKey);
    }

    /** 纯校验：是否是中国大陆手机号 */
    public static function isCnMobile(string $phoneArea, string $mobile): bool
    {
        $area = ltrim(trim($phoneArea), '+0');

        return ($area === '' || $area === '86') && preg_match('/^1[3-9]\d{9}$/', $mobile) === 1;
    }

    private function normalize(string $purpose, string $phoneArea, string $mobile): string
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new BusinessException('不支持的验证码用途');
        }
        if (!$this->enabled()) {
            throw new BusinessException('短信服务未开通');
        }
        $mobile = trim($mobile);
        if (!self::isCnMobile($phoneArea, $mobile)) {
            throw new BusinessException('短信验证码目前只支持中国大陆手机号');
        }

        return $mobile;
    }

    private function wrongKey(string $purpose, string $mobile): string
    {
        return "sms:wrong:{$purpose}:{$mobile}";
    }
}
