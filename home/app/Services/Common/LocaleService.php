<?php

namespace App\Services\Common;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 启用的语言与币种（带缓存）。后台修改语言/币种后必须调用 flush()。
 * 数据库不可用时（如迁移期间）回退到 config/mall.php 的默认值，不抛异常。
 */
class LocaleService
{
    private const KEY_LANG = 'mall:locale:languages';
    private const KEY_CUR = 'mall:locale:currencies';

    /** @return array<int, array{code:string,name:string,is_default:int}> */
    public function languages(): array
    {
        $v = Cache::get(self::KEY_LANG);
        if ($v === null) {
            try {
                $v = DB::table('language')->where('status', 1)->orderBy('sort')->orderBy('id')
                    ->get(['code', 'name', 'is_default'])
                    ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'is_default' => (int) $r->is_default])
                    ->all();
            } catch (\Throwable $e) {
                return [];
            }
            if ($v !== []) {
                Cache::put(self::KEY_LANG, $v, 3600);
            }
        }

        return $v;
    }

    public function codes(): array
    {
        $codes = array_column($this->languages(), 'code');

        return $codes ?: (array) config('mall.locales');
    }

    public function defaultLang(): string
    {
        foreach ($this->languages() as $l) {
            if ($l['is_default'] === 1) {
                return $l['code'];
            }
        }

        return $this->languages()[0]['code'] ?? (string) config('mall.default_locale');
    }

    /** @return array<int, array{code:string,name:string,symbol:string,rate:string,decimals:int,is_base:int}> */
    public function currencies(): array
    {
        $v = Cache::get(self::KEY_CUR);
        if ($v === null) {
            try {
                $v = DB::table('currency')->where('status', 1)->orderBy('sort')->orderBy('id')
                    ->get(['code', 'name', 'symbol', 'rate', 'decimals', 'is_base'])
                    ->map(fn ($r) => [
                        'code' => $r->code, 'name' => $r->name, 'symbol' => $r->symbol,
                        'rate' => (string) $r->rate, 'decimals' => (int) $r->decimals, 'is_base' => (int) $r->is_base,
                    ])->all();
            } catch (\Throwable $e) {
                return [];
            }
            if ($v !== []) {
                Cache::put(self::KEY_CUR, $v, 3600);
            }
        }

        return $v;
    }

    public function currencyCodes(): array
    {
        $codes = array_column($this->currencies(), 'code');

        return $codes ?: (array) config('mall.currencies');
    }

    public function baseCurrency(): string
    {
        foreach ($this->currencies() as $c) {
            if ($c['is_base'] === 1) {
                return $c['code'];
            }
        }

        return (string) config('mall.base_currency');
    }

    public function flush(): void
    {
        Cache::forget(self::KEY_LANG);
        Cache::forget(self::KEY_CUR);
    }
}
