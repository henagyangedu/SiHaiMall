<?php

namespace App\Drivers\Translate;

use App\Drivers\Contracts\TranslateDriver;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Anthropic Claude 的 Messages 接口（POST /v1/messages）。
 * 和 OpenAI 兼容接口的差别：鉴权用 x-api-key 头，system 提示词是单独的字段，必须给出 max_tokens，
 * 没有“强制 JSON 输出”的开关——是否输出 JSON 由提示词约束，调用方（TranslateService）会校验并在不合格时重试。
 * 模型名不写死，全部来自后台配置。
 */
class ClaudeDriver implements TranslateDriver
{
    private const API_VERSION = '2023-06-01';

    public function __construct(private array $config) {}

    public function code(): string
    {
        return 'claude';
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
            'model'      => $this->model(),
            'max_tokens' => max(256, min(64000, (int) ($this->config['max_tokens'] ?? 8192) ?: 8192)),
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
        ];
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
            $res = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => self::API_VERSION])->acceptJson()
                ->timeout($timeout)->withOptions($options)->post($base . '/v1/messages', $payload);
        } catch (\Throwable $e) {
            throw new BusinessException('连接模型服务失败：' . Str::limit(str_replace($key, '***', $e->getMessage()), 200));
        }

        if (!$res->successful()) {
            $msg = (string) ($res->json('error.message') ?? $res->body());
            throw new BusinessException('模型服务返回错误（' . $res->status() . '）：' . Str::limit(str_replace($key, '***', $msg), 200));
        }

        // content 是分块的数组，把其中的文本块拼起来
        $text = '';
        foreach ((array) $res->json('content') as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
        if (trim($text) === '') {
            throw new BusinessException('模型没有返回内容');
        }
        // 输出被长度上限截断：译文不完整，不能当成结果用
        if ($res->json('stop_reason') === 'max_tokens') {
            throw new BusinessException('译文超过了“最大输出长度”被截断，请在模型配置里调大，或把内容分段翻译');
        }

        return $text;
    }
}
