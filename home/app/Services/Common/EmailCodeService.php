<?php

namespace App\Services\Common;

use App\Mail\VerifyCodeMail;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 邮箱验证码（邮箱注册、邮箱找回密码）。验证码由商城生成，只在缓存里存哈希，校验通过即失效。
 * - 开关：基础设置里的 email_verify_enabled（默认关）；发信走 Laravel 邮件配置（.env 的 MAIL_*）
 * - 有效期 10 分钟；同一邮箱 60 秒一次、每天最多 10 次；同一 IP 每小时最多 20 次
 * - 同一邮箱同一用途 10 分钟内最多错 5 次，超过后要重新获取
 */
class EmailCodeService
{
    public const PURPOSES = ['register', 'reset'];

    private const TTL_MINUTES = 10;
    private const COOLDOWN = 60;
    private const DAILY_PER_EMAIL = 10;
    private const HOURLY_PER_IP = 20;
    private const MAX_WRONG = 5;

    public function __construct(private ConfigService $config) {}

    public function enabled(): bool
    {
        return (int) $this->config->value('base', 'email_verify_enabled') === 1;
    }

    public function send(string $purpose, string $email, string $ip, string $lang): void
    {
        $email = $this->normalize($purpose, $email);
        $id = sha1($email);

        $coolKey = "emailcode:cool:{$id}";
        $dayKey = "emailcode:day:{$id}:" . date('Ymd');
        $ipKey = "emailcode:ip:{$ip}:" . date('YmdH');
        if (Cache::has($coolKey)) {
            throw new BusinessException('验证码发送过于频繁，请 1 分钟后再试');
        }
        if ((int) Cache::get($dayKey, 0) >= self::DAILY_PER_EMAIL) {
            throw new BusinessException('该邮箱今天获取验证码的次数已达上限');
        }
        if ((int) Cache::get($ipKey, 0) >= self::HOURLY_PER_IP) {
            throw new BusinessException('操作过于频繁，请稍后再试');
        }

        $code = (string) random_int(100000, 999999);
        // 先占住冷却，避免并发请求重复发信；发信失败再释放
        Cache::put($coolKey, 1, self::COOLDOWN);
        try {
            Mail::to($email)->send(new VerifyCodeMail($code, $purpose, self::TTL_MINUTES, $this->siteName($lang), $lang));
        } catch (\Throwable $e) {
            Cache::forget($coolKey);
            Log::warning('邮箱验证码发送失败', ['purpose' => $purpose, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            throw new BusinessException('验证码邮件发送失败，请稍后再试');
        }

        Cache::put($this->codeKey($purpose, $id), self::hash($email, $code), self::TTL_MINUTES * 60);
        Cache::forget($this->wrongKey($purpose, $id));
        Cache::add($dayKey, 0, 86400);
        Cache::increment($dayKey);
        Cache::add($ipKey, 0, 3600);
        Cache::increment($ipKey);
    }

    /** 校验验证码，不通过直接抛 BusinessException；通过后验证码立即失效 */
    public function verify(string $purpose, string $email, string $code): void
    {
        $email = $this->normalize($purpose, $email);
        $id = sha1($email);
        $codeKey = $this->codeKey($purpose, $id);
        $wrongKey = $this->wrongKey($purpose, $id);

        if ((int) Cache::get($wrongKey, 0) >= self::MAX_WRONG) {
            throw new BusinessException('验证码错误次数过多，请重新获取验证码');
        }
        $stored = Cache::get($codeKey);
        if (!is_string($stored) || !hash_equals($stored, self::hash($email, trim($code)))) {
            Cache::add($wrongKey, 0, 600);
            Cache::increment($wrongKey);
            throw new BusinessException('验证码错误或已过期');
        }
        Cache::forget($codeKey);
        Cache::forget($wrongKey);
    }

    private function normalize(string $purpose, string $email): string
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new BusinessException('不支持的验证码用途');
        }
        if (!$this->enabled()) {
            throw new BusinessException('邮箱验证未开启');
        }
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new BusinessException('邮箱格式不正确');
        }

        return $email;
    }

    private function siteName(string $lang): string
    {
        return trim((string) $this->config->value('base', 'site_name', $lang)) ?: (string) config('app.name');
    }

    private static function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email . '|' . $code, (string) config('app.key'));
    }

    private function codeKey(string $purpose, string $id): string
    {
        return "emailcode:code:{$purpose}:{$id}";
    }

    private function wrongKey(string $purpose, string $id): string
    {
        return "emailcode:wrong:{$purpose}:{$id}";
    }
}
