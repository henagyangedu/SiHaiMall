<?php

namespace App\Drivers\Sms;

use App\Drivers\Contracts\SmsDriver;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * GM 短信平台（sms.gmwang.com.cn），对接文档见 docs/短信平台接入文档.md。
 * 鉴权：X-Gm-AppKey / X-Gm-Timestamp / X-Gm-Nonce / X-Gm-Sign，
 *       Sign = HMAC-SHA256(AppSecret, AppKey \n Timestamp \n Nonce \n SHA256(原始报文))。
 * 只用平台的验证码接口（/verify-code/send、/verify-code/check）：验证码由平台生成，发送频次和有效期由平台控制。
 */
class GmSmsDriver implements SmsDriver
{
    /** 平台错误码 → 给用户看的提示。没列出的一律提示稍后再试，原始信息只写日志 */
    private const SEND_ERRORS = [
        20006 => '验证码发送过于频繁，请稍后再试',
        40006 => '手机号格式不正确',
        40007 => '该手机号无法接收短信，请联系客服',
    ];

    /** 这些错误码说明是配置、额度或平台故障，而不是用户输错了验证码 */
    private const FAULT_RANGES = [[20001, 20006], [30001, 30004], [40001, 40005], [40008, 40009], [50000, 50001]];

    public function __construct(private array $config) {}

    public function code(): string
    {
        return 'gmsms';
    }

    public function sendCode(string $purpose, string $mobile): void
    {
        $res = $this->request('POST', '/verify-code/send', ['scene' => $this->scene($purpose), 'mobile' => $mobile]);
        if ($res['code'] !== 0) {
            Log::warning('短信验证码发送失败', ['purpose' => $purpose, 'mobile' => self::mask($mobile), 'code' => $res['code'], 'msg' => $res['msg']]);
            throw new BusinessException(self::SEND_ERRORS[$res['code']] ?? '验证码发送失败，请稍后再试');
        }
    }

    public function checkCode(string $purpose, string $mobile, string $code): bool
    {
        $res = $this->request('POST', '/verify-code/check', ['scene' => $this->scene($purpose), 'mobile' => $mobile, 'code' => $code]);
        if ($res['code'] === 0) {
            return true;
        }
        if (self::isFault($res['code'])) {
            Log::warning('短信验证码校验异常', ['purpose' => $purpose, 'mobile' => self::mask($mobile), 'code' => $res['code'], 'msg' => $res['msg']]);
            throw new BusinessException('验证码校验服务暂时不可用，请稍后再试');
        }

        return false;
    }

    /** 查询余额：验证接口地址、AppKey、AppSecret 和出口 IP 白名单都可用，不会发短信 */
    public function ping(): array
    {
        $res = $this->request('GET', '/quota');
        if ($res['code'] !== 0) {
            throw new BusinessException("短信平台返回错误（{$res['code']}）：" . Str::limit($res['msg'], 200));
        }

        return ['quotas' => $res['data']['quotas'] ?? []];
    }

    /** 纯计算：按文档生成签名（小写十六进制） */
    public static function sign(string $appKey, string $appSecret, string $timestamp, string $nonce, string $rawBody): string
    {
        return hash_hmac('sha256', implode("\n", [$appKey, $timestamp, $nonce, hash('sha256', $rawBody)]), $appSecret);
    }

    public static function isFault(int $code): bool
    {
        foreach (self::FAULT_RANGES as [$from, $to]) {
            if ($code >= $from && $code <= $to) {
                return true;
            }
        }

        return false;
    }

    /** @return array{code:int,msg:string,data:array} */
    private function request(string $method, string $path, array $payload = []): array
    {
        $base = rtrim(trim((string) ($this->config['base_url'] ?? '')), '/');
        $key = trim((string) ($this->config['app_key'] ?? ''));
        $secret = trim((string) ($this->config['app_secret'] ?? ''));
        if ($base === '' || $key === '' || $secret === '') {
            throw new BusinessException('短信接口配置不完整（接口地址、AppKey、AppSecret）');
        }

        // 先序列化，再对原始报文签名，再原样发送；GET 请求的报文为空字符串
        $body = $method === 'GET' ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $nonce = Str::random(32);
        $headers = [
            'X-Gm-AppKey' => $key, 'X-Gm-Timestamp' => $ts, 'X-Gm-Nonce' => $nonce,
            'X-Gm-Sign' => self::sign($key, $secret, $ts, $nonce, $body),
        ];

        $options = ['connect_timeout' => 8];
        $proxy = trim((string) ($this->config['proxy'] ?? ''));
        if ($proxy !== '') {
            $options['proxy'] = $proxy;
        }
        $http = Http::withHeaders($headers)->acceptJson()->timeout(max(5, min(60, (int) ($this->config['timeout'] ?? 15))))->withOptions($options);

        try {
            $res = $method === 'GET' ? $http->get($base . $path) : $http->withBody($body, 'application/json')->post($base . $path);
        } catch (\Throwable $e) {
            throw new BusinessException('连接短信平台失败：' . Str::limit(str_replace([$secret, $key], '***', $e->getMessage()), 200));
        }

        $json = $res->json();
        if (!is_array($json) || !array_key_exists('code', $json)) {
            throw new BusinessException('短信平台返回了无法识别的内容（HTTP ' . $res->status() . '）');
        }

        return ['code' => (int) $json['code'], 'msg' => (string) ($json['msg'] ?? ''), 'data' => is_array($json['data'] ?? null) ? $json['data'] : []];
    }

    /** 商城用途 → 平台分配的场景标识（后台“接口配置 → 短信”里填写） */
    private function scene(string $purpose): string
    {
        $scene = trim((string) ($this->config['scene_' . $purpose] ?? ''));
        if ($scene === '') {
            throw new BusinessException('短信场景未配置：' . $purpose);
        }

        return $scene;
    }

    private static function mask(string $mobile): string
    {
        return strlen($mobile) >= 7 ? substr($mobile, 0, 3) . '****' . substr($mobile, -4) : '****';
    }
}
