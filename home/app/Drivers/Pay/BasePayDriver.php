<?php

namespace App\Drivers\Pay;

use App\Support\Exceptions\BusinessException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** 支付驱动公共：HTTP 客户端（超时、代理）与配置读取 */
abstract class BasePayDriver
{
    public function __construct(protected array $config = []) {}

    protected function cfg(string $key, string $default = ''): string
    {
        return trim((string) ($this->config[$key] ?? $default));
    }

    protected function http(): PendingRequest
    {
        $req = Http::timeout(max((int) $this->cfg('timeout', '30'), 5))->acceptJson();
        $proxy = $this->cfg('proxy');
        if ($proxy !== '') {
            $req = $req->withOptions(['proxy' => $proxy]);
        }

        return $req;
    }

    /** 渠道返回非 2xx 时抛出业务异常（不把渠道原始响应、密钥透传给前端，只截取错误信息） */
    protected function fail(string $what, ?array $json, int $status): never
    {
        $msg = $json['error']['message'] ?? $json['message'] ?? $json['error_description'] ?? $json['name'] ?? ('HTTP ' . $status);

        throw new BusinessException("{$what}失败：" . mb_substr((string) $msg, 0, 200));
    }
}
