<?php

namespace App\Drivers\App;

use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google 登录（Sign in with Google）。
 *
 * 买家在页面上点 Google 的登录按钮，Google 把一个“身份凭证”（ID token，一段带签名的 JWT）交给页面，页面再提交给商城。
 * 这里只做一件事：验证这个凭证确实是 Google 签发给本站的、没有过期，然后取出里面的用户标识和邮箱。
 * 不需要客户端密钥，也不向 Google 提交任何买家数据；唯一的对外请求是取 Google 的公钥（有缓存）。
 */
class GoogleLoginDriver
{
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /** 公钥缓存多久。Google 的公钥几天到几周轮换一次，遇到不认识的 kid 会立刻重新取 */
    private const KEYS_TTL = 21600;

    public function __construct(private array $config) {}

    public function clientId(): string
    {
        return trim((string) ($this->config['client_id'] ?? ''));
    }

    /**
     * 验证身份凭证。
     * @return array{sub:string,email:string,name:string} sub 是 Google 账号的固定标识；email 只有 Google 确认验证过才给，否则为空串
     */
    public function verify(string $credential): array
    {
        if ($this->clientId() === '') {
            throw new BusinessException('Google 登录还没有配置完整');
        }
        // 买家只看到一句通用提示；哪一步没通过记在日志里（不记凭证本身）
        $bad = function (string $why): BusinessException {
            Log::info('Google 登录凭证没有通过验证', ['step' => $why]);

            return new BusinessException('Google 登录凭证无效，请重试');
        };

        $parts = explode('.', trim($credential));
        if (count($parts) !== 3) {
            throw $bad('format');
        }
        $header = json_decode(self::b64($parts[0]), true);
        $claims = json_decode(self::b64($parts[1]), true);
        $signature = self::b64($parts[2]);
        if (!is_array($header) || !is_array($claims) || $signature === '' || ($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null)) {
            throw $bad('header');
        }

        $pem = $this->publicKey($header['kid']);
        if ($pem === null || openssl_verify($parts[0] . '.' . $parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw $bad('signature');
        }
        // 签名对了再看内容：签发方、是不是发给本站的、有没有过期（允许 60 秒的时钟误差）
        $now = time();
        if (!in_array($claims['iss'] ?? '', self::ISSUERS, true) || ($claims['aud'] ?? '') !== $this->clientId()
            || (int) ($claims['exp'] ?? 0) < $now - 60 || (int) ($claims['iat'] ?? 0) > $now + 60) {
            throw $bad('claims');
        }
        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '' || strlen($sub) > 120) {
            throw $bad('sub');
        }
        $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true';
        $email = $verified ? strtolower(trim((string) ($claims['email'] ?? ''))) : '';

        return [
            'sub'   => $sub,
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 100 ? $email : '',
            'name'  => trim((string) ($claims['name'] ?? '')),
        ];
    }

    /** 连通性测试：服务器能不能取到 Google 的公钥（国内服务器直连通常不通，要填代理） */
    public function ping(): array
    {
        if ($this->clientId() === '') {
            throw new BusinessException('Google 登录还没有配置完整');
        }
        if (!preg_match('/^[0-9]+-[0-9a-z]+\.apps\.googleusercontent\.com$/', $this->clientId())) {
            throw new BusinessException('测试失败：客户端 ID 的格式不对，应该是“一串数字-一串字母数字.apps.googleusercontent.com”');
        }
        $keys = $this->fetchKeys();
        if ($keys === []) {
            throw new BusinessException('测试失败：服务器取不到 Google 的公钥。服务器在国内时一般要填代理');
        }

        return ['ok' => true, 'keys' => count($keys)];
    }

    /** 按 kid 找公钥（PEM）。缓存里没有这个 kid 时重新取一次（Google 轮换了密钥） */
    private function publicKey(string $kid): ?string
    {
        $cacheKey = 'google_login_keys:' . sha1($this->certsUrl());
        $keys = Cache::get($cacheKey);
        if (!is_array($keys) || !isset($keys[$kid])) {
            $keys = $this->fetchKeys();
            if ($keys === []) {
                throw new BusinessException('Google 登录暂时不可用，请稍后再试');
            }
            Cache::put($cacheKey, $keys, self::KEYS_TTL);
        }

        return $keys[$kid] ?? null;
    }

    /** @return array<string,string> kid => PEM；取不到时返回空数组 */
    private function fetchKeys(): array
    {
        try {
            $http = Http::timeout(max((int) ($this->config['timeout'] ?? 10), 3));
            if (($proxy = trim((string) ($this->config['proxy'] ?? ''))) !== '') {
                $http = $http->withOptions(['proxy' => $proxy]);
            }
            $data = $http->get($this->certsUrl())->json();
        } catch (\Throwable $e) {
            Log::warning('取 Google 登录公钥失败', ['error' => $e::class]);

            return [];
        }
        $keys = [];
        foreach ((array) ($data['keys'] ?? []) as $jwk) {
            if (($jwk['kty'] ?? '') === 'RSA' && is_string($jwk['kid'] ?? null) && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
                $pem = self::rsaPem(self::b64($jwk['n']), self::b64($jwk['e']));
                if ($pem !== null) {
                    $keys[$jwk['kid']] = $pem;
                }
            }
        }

        return $keys;
    }

    private function certsUrl(): string
    {
        return trim((string) ($this->config['certs_url'] ?? '')) ?: 'https://www.googleapis.com/oauth2/v3/certs';
    }

    private static function b64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** 把 RSA 公钥的模数和指数（二进制）拼成 PEM（X.509 SubjectPublicKeyInfo），给 openssl 用 */
    private static function rsaPem(string $n, string $e): ?string
    {
        if ($n === '' || $e === '') {
            return null;
        }
        $len = function (int $l): string {
            if ($l < 0x80) {
                return chr($l);
            }
            $bytes = ltrim(pack('N', $l), "\x00");

            return chr(0x80 | strlen($bytes)) . $bytes;
        };
        // 整数是有符号的：最高位是 1 时前面补一个 0 字节
        $int = function (string $b) use ($len): string {
            $b = ltrim($b, "\x00");
            if ($b === '' || (ord($b[0]) & 0x80)) {
                $b = "\x00" . $b;
            }

            return "\x02" . $len(strlen($b)) . $b;
        };
        $seq = fn (string $body) => "\x30" . $len(strlen($body)) . $body;

        $rsaKey = $seq($int($n) . $int($e));
        // rsaEncryption 的对象标识 1.2.840.113549.1.1.1 + NULL
        $algorithm = $seq("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $der = $seq($algorithm . "\x03" . $len(strlen($rsaKey) + 1) . "\x00" . $rsaKey);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";

        return openssl_pkey_get_public($pem) === false ? null : $pem;
    }
}
