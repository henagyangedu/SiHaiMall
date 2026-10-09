<?php

namespace App\Services\Api;

use App\Services\Common\ConfigService;
use App\Services\Common\LocaleService;
use App\Support\Exceptions\BusinessException;

/** C 端协议与政策：内容在后台“设置 → 协议管理”维护（多语言），缺少当前语言时回退到默认语言 */
class ProtocolService
{
    /** 标题：中文语言用中文，其他语言一律用英文 */
    private const TITLES = [
        'user_agreement'         => ['zh' => '用户协议', 'en' => 'Terms of Service'],
        'privacy_policy'         => ['zh' => '隐私政策', 'en' => 'Privacy Policy'],
        'refund_policy'          => ['zh' => '退换货政策', 'en' => 'Return & Refund Policy'],
        'shipping_policy'        => ['zh' => '配送政策', 'en' => 'Shipping Policy'],
        'distribution_agreement' => ['zh' => '分销员协议', 'en' => 'Affiliate Agreement'],
    ];

    public function __construct(private ConfigService $config, private LocaleService $locale) {}

    /** 已填写内容的协议列表（没填的不返回，前端据此决定显示哪些入口） */
    public function list(string $lang): array
    {
        $out = [];
        foreach (array_keys(self::TITLES) as $key) {
            if ($this->content($key, $lang) !== '') {
                $out[] = ['key' => $key, 'title' => $this->title($key, $lang)];
            }
        }

        return $out;
    }

    public function detail(string $key, string $lang): array
    {
        if (!isset(self::TITLES[$key])) {
            throw new BusinessException('协议不存在');
        }

        return ['key' => $key, 'title' => $this->title($key, $lang), 'content' => $this->content($key, $lang)];
    }

    private function content(string $key, string $lang): string
    {
        $all = (array) $this->config->get('protocol')[$key];
        $text = trim((string) ($all[$lang] ?? ''));
        // 富文本编辑器清空后会留下空段落，视为没填
        if ($text === '' || trim(strip_tags($text)) === '') {
            $text = trim((string) ($all[$this->locale->defaultLang()] ?? ''));
        }

        return trim(strip_tags($text)) === '' ? '' : $text;
    }

    private function title(string $key, string $lang): string
    {
        return self::TITLES[$key][str_starts_with(strtolower($lang), 'zh') ? 'zh' : 'en'];
    }
}
