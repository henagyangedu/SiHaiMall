<?php

namespace App\Drivers\App;

use App\Support\Exceptions\BusinessException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 微信小程序服务端接口（api.weixin.qq.com）。目前只用于登录：把小程序 wx.login 拿到的 code 换成 openid。
 * 微信返回的 session_key 用不到，不保存也不写日志。
 */
class WechatMiniDriver
{
    /** code 无效、已被用过、已过期：让买家重新点一次即可 */
    private const BAD_CODE = [40029, 40163, 40226];

    public function __construct(private array $config) {}

    public function appId(): string
    {
        return trim((string) ($this->config['app_id'] ?? ''));
    }

    /**
     * 用登录凭证换取用户标识。
     * @return array{openid:string,unionid:string} unionid 只有小程序绑定了微信开放平台才有，否则为空串
     */
    public function session(string $code): array
    {
        $data = $this->get('/sns/jscode2session', [
            'appid' => $this->appId(), 'secret' => $this->secret(), 'js_code' => trim($code), 'grant_type' => 'authorization_code',
        ]);
        $openid = (string) ($data['openid'] ?? '');
        if ($openid === '') {
            $errcode = (int) ($data['errcode'] ?? 0);
            if (in_array($errcode, self::BAD_CODE, true)) {
                throw new BusinessException('微信登录凭证已失效，请重试');
            }
            Log::warning('微信小程序 code 换 openid 失败', ['errcode' => $errcode, 'errmsg' => mb_substr((string) ($data['errmsg'] ?? ''), 0, 200)]);
            throw new BusinessException('微信登录暂时不可用，请稍后再试');
        }

        return ['openid' => $openid, 'unionid' => (string) ($data['unionid'] ?? '')];
    }

    /** 连通性测试：用 AppID 和 AppSecret 取一次接口调用凭证，能取到说明两者正确 */
    public function ping(): array
    {
        $data = $this->get('/cgi-bin/token', ['grant_type' => 'client_credential', 'appid' => $this->appId(), 'secret' => $this->secret()]);
        if ((string) ($data['access_token'] ?? '') === '') {
            $errcode = (int) ($data['errcode'] ?? 0);
            throw new BusinessException('测试失败：' . mb_substr((string) ($data['errmsg'] ?? '微信返回了无法识别的内容'), 0, 200) . ($errcode ? "（{$errcode}）" : ''));
        }

        return ['ok' => true];
    }

    private function secret(): string
    {
        return trim((string) ($this->config['app_secret'] ?? ''));
    }

    /** @param array<string,string> $query */
    private function get(string $path, array $query): array
    {
        if ($this->appId() === '' || $this->secret() === '') {
            throw new BusinessException('小程序还没有配置完整');
        }
        try {
            $data = $this->http()->get(rtrim((string) ($this->config['api_base'] ?? '') ?: 'https://api.weixin.qq.com', '/') . $path, $query)->json();
        } catch (\Throwable $e) {
            // 异常信息里可能带着完整的请求地址（含 AppSecret），所以只记录异常类型
            Log::warning('微信小程序接口请求失败', ['path' => $path, 'error' => $e::class]);
            throw new BusinessException('微信登录暂时不可用，请稍后再试');
        }

        return is_array($data) ? $data : [];
    }

    private function http(): PendingRequest
    {
        $http = Http::timeout(max((int) ($this->config['timeout'] ?? 10), 3));
        if (($proxy = trim((string) ($this->config['proxy'] ?? ''))) !== '') {
            $http = $http->withOptions(['proxy' => $proxy]);
        }

        return $http;
    }
}
