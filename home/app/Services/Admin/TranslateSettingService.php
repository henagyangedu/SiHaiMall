<?php

namespace App\Services\Admin;

use App\Drivers\DriverRegistry;
use App\Models\DriverConfig;
use App\Services\Common\LocaleService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/** AI 翻译的路由与额度设置，存放在 gm_config（group = translate） */
class TranslateSettingService
{
    private const GROUP = 'translate';

    public function __construct(private LocaleService $locale) {}

    public function get(): array
    {
        $raw = DB::table('config')->where('group', self::GROUP)->pluck('value', 'key')->all();
        $routes = json_decode((string) ($raw['routes'] ?? ''), true);
        if (!is_array($routes)) {
            $routes = [];
        }

        return [
            'default_route'    => [
                'primary'  => (string) ($routes['default']['primary'] ?? ''),
                'fallback' => (string) ($routes['default']['fallback'] ?? ''),
            ],
            'lang_routes'      => (object) array_map(
                fn ($r) => ['primary' => (string) ($r['primary'] ?? ''), 'fallback' => (string) ($r['fallback'] ?? '')],
                (array) ($routes['langs'] ?? [])
            ),
            'daily_limit_chars' => (int) ($raw['daily_limit_chars'] ?? 200000),
            'prompt_extra'     => (string) ($raw['prompt_extra'] ?? ''),
        ];
    }

    public function save(array $d): void
    {
        $drivers = array_keys(DriverRegistry::drivers('translate'));
        $langs = $this->locale->codes();
        $okDriver = fn ($c) => $c === '' || in_array($c, $drivers, true);

        $default = (array) ($d['default_route'] ?? []);
        $routes = ['default' => [
            'primary'  => (string) ($default['primary'] ?? ''),
            'fallback' => (string) ($default['fallback'] ?? ''),
        ], 'langs' => []];
        foreach ([$routes['default']['primary'], $routes['default']['fallback']] as $c) {
            if (!$okDriver($c)) {
                throw new BusinessException('未知的模型：' . $c);
            }
        }
        foreach ((array) ($d['lang_routes'] ?? []) as $code => $r) {
            $p = (string) ($r['primary'] ?? '');
            $f = (string) ($r['fallback'] ?? '');
            if (!in_array($code, $langs, true) || (!$okDriver($p)) || (!$okDriver($f))) {
                throw new BusinessException('语言路由配置不正确：' . $code);
            }
            if ($p !== '' || $f !== '') {
                $routes['langs'][$code] = ['primary' => $p, 'fallback' => $f];
            }
        }

        $values = [
            'routes'            => json_encode($routes, JSON_UNESCAPED_UNICODE),
            'daily_limit_chars' => (string) max(0, (int) ($d['daily_limit_chars'] ?? 0)),
            'prompt_extra'      => trim((string) ($d['prompt_extra'] ?? '')),
        ];
        DB::transaction(function () use ($values) {
            foreach ($values as $key => $val) {
                DB::table('config')->updateOrInsert(
                    ['group' => self::GROUP, 'key' => $key],
                    ['value' => $val, 'is_lang' => 0, 'remark' => 'AI 翻译设置', 'updated_at' => now()]
                );
            }
        });
    }

    /** 目标语言对应的 [主模型, 备用模型]（只返回已启用的；未指定主模型时取任一已启用模型） */
    public function candidatesFor(string $lang): array
    {
        $enabled = DriverConfig::query()
            ->where('type', 'translate')->where('is_enabled', 1)
            ->orderByDesc('is_default')->orderBy('sort')->pluck('driver')->all();
        if ($enabled === []) {
            throw new BusinessException('请先在“接口配置 → AI 翻译”配置并启用模型');
        }

        $s = $this->get();
        $route = ((array) $s['lang_routes'])[$lang] ?? $s['default_route'];

        $list = [];
        foreach ([$route['primary'] ?? '', $route['fallback'] ?? ''] as $c) {
            if ($c !== '' && in_array($c, $enabled, true) && !in_array($c, $list, true)) {
                $list[] = $c;
            }
        }

        return $list ?: [$enabled[0]];
    }
}
