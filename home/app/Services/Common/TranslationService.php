<?php

namespace App\Services\Common;

use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * 多语言翻译表工具（主表 + xxx_lang 表，翻译表主键为 外键 + lang）。
 *
 * 数据形状（与前端 LangInput 一致）：
 *   读取：[主表ID => [字段 => [语言码 => 文本]]]
 *   写入：[字段 => [语言码 => 文本]]
 */
class TranslationService
{
    public function __construct(private LocaleService $locale) {}

    /** 批量读取翻译 */
    public function fetch(string $table, string $fk, array $ids, array $fields): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (DB::table($table)->whereIn($fk, $ids)->get() as $r) {
            foreach ($fields as $f) {
                $out[$r->{$fk}][$f][$r->lang] = (string) $r->{$f};
            }
        }

        return $out;
    }

    /**
     * 保存翻译：只处理当前启用的语言；某语言的所有字段都为空且原来没有记录时不会产生空行。
     * 注意：翻译表里没有默认值的 NOT NULL 字段必须都在 $fields 里提供。
     */
    public function sync(string $table, string $fk, int $id, array $data, array $fields): void
    {
        foreach ($this->locale->codes() as $code) {
            $row = [];
            foreach ($fields as $f) {
                if (isset($data[$f]) && is_array($data[$f]) && array_key_exists($code, $data[$f])) {
                    $row[$f] = trim((string) ($data[$f][$code] ?? ''));
                }
            }
            if ($row === []) {
                continue;
            }

            $key = [$fk => $id, 'lang' => $code];
            $allEmpty = count(array_filter($row, fn ($v) => $v !== '')) === 0;
            if ($allEmpty && !DB::table($table)->where($key)->exists()) {
                continue;
            }
            DB::table($table)->updateOrInsert($key, $row);
        }
    }

    /** 要求默认语言必填（其它语言缺失时回退到默认语言显示） */
    public function assertDefaultFilled(array $texts, string $label): void
    {
        $default = $this->locale->defaultLang();
        if (trim((string) ($texts[$default] ?? '')) === '') {
            throw new BusinessException("请填写默认语言（{$default}）的{$label}");
        }
    }

    /** 取指定语言文本，缺失时依次回退：默认语言 → 任一非空语言 */
    public function resolve(array $texts, ?string $lang = null): string
    {
        $lang ??= $this->locale->defaultLang();
        foreach ([$lang, $this->locale->defaultLang()] as $code) {
            if (trim((string) ($texts[$code] ?? '')) !== '') {
                return (string) $texts[$code];
            }
        }
        foreach ($texts as $t) {
            if (trim((string) $t) !== '') {
                return (string) $t;
            }
        }

        return '';
    }
}
