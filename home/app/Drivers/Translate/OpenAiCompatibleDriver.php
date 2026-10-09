<?php

namespace App\Drivers\Translate;

use App\Drivers\Contracts\TranslateDriver;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OpenAI 兼容的 Chat Completions 接口：OpenAI、DeepSeek、通义千问等都提供这种接口，
 * 差别只在 base_url、api_key、model，所以共用本驱动。模型名不写死，全部来自后台配置。
 */
class OpenAiCompatibleDriver implements TranslateDriver
{
    public function __construct(private string $code, private array $config) {}

    public function code(): string
    {
        return $this->code;
    }

    public function model(): string
    {
        return trim((string) ($this->config['model'] ?? ''));
    }

    public function complete(string $system, string $user, bool $json = true): string
    {
        $base = rtrim(trim((string) ($this->config['base_url'] ?? '')), '/');
        $key = trim((string) ($this->config['api_key'] ?? ''));
        if ($base === '' || $key === '' || $this->model() === '') {
            throw new BusinessException('模型配置不完整（接口地址、密钥、模型名称）');
        }

        $payload = [
            'model'    => $this->model(),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];
        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $temp = trim((string) ($this->config['temperature'] ?? ''));
        if ($temp !== '' && is_numeric($temp)) {
            $payload['temperature'] = (float) $temp; // 部分新模型不支持自定义温度，留空即可
        }

        $options = ['connect_timeout' => 10];
        $proxy = trim((string) ($this->config['proxy'] ?? ''));
        if ($proxy !== '') {
            $options['proxy'] = $proxy;
        }
        $timeout = max(10, min(300, (int) ($this->config['timeout'] ?? 60)));

        try {
            $res = Http::withToken($key)->acceptJson()->timeout($timeout)->withOptions($options)
                ->post($base . '/chat/completions', $payload);
        } catch (\Throwable $e) {
            throw new BusinessException('连接模型服务失败：' . Str::limit(str_replace($key, '***', $e->getMessage()), 200));
        }

        if (!$res->successful()) {
            $msg = (string) ($res->json('error.message') ?? $res->body());
            throw new BusinessException('模型服务返回错误（' . $res->status() . '）：' . Str::limit(str_replace($key, '***', $msg), 200));
        }

        $text = $res->json('choices.0.message.content');
        if (!is_string($text) || trim($text) === '') {
            throw new BusinessException('模型没有返回内容');
        }

        return $text;
    }
}
