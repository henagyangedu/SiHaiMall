<?php

namespace App\Services\Admin;

use App\Models\Language;
use App\Services\Common\LocaleService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

class LanguageService
{
    /** 存放多语言翻译的表：已有翻译数据的语言不允许删除，只能禁用 */
    private const TRANSLATION_TABLES = [
        'category_lang', 'brand_lang', 'goods_lang', 'goods_spec_lang', 'goods_spec_value_lang',
        'member_level_lang', 'message_template_lang',
    ];

    public function __construct(private LocaleService $locale) {}

    public function list(): array
    {
        return Language::query()->orderBy('sort')->orderBy('id')->get()->toArray();
    }

    /** 启用的语言（各个多语言表单的页签用） */
    public function options(): array
    {
        return $this->locale->languages();
    }

    public function create(array $d): Language
    {
        if (Language::query()->where('code', $d['code'])->exists()) {
            throw new BusinessException('语言编码已存在');
        }
        $first = !Language::query()->exists();

        $lang = Language::query()->create([
            'code'       => $d['code'],
            'name'       => $d['name'],
            'is_default' => $first ? 1 : 0,
            'status'     => $first ? 1 : (int) ($d['status'] ?? 1),
            'sort'       => (int) ($d['sort'] ?? 0),
        ]);
        $this->locale->flush();

        return $lang;
    }

    public function update(int $id, array $d): Language
    {
        $lang = $this->find($id);
        $status = array_key_exists('status', $d) ? (int) $d['status'] : (int) $lang->status;
        if ((int) $lang->is_default === 1 && $status !== 1) {
            throw new BusinessException('默认语言不能禁用，请先把其它语言设为默认');
        }

        $lang->update([
            'name'   => $d['name'],
            'status' => $status,
            'sort'   => (int) ($d['sort'] ?? $lang->sort),
        ]);
        $this->locale->flush();

        return $lang;
    }

    public function setDefault(int $id): void
    {
        $lang = $this->find($id);
        if ((int) $lang->status !== 1) {
            throw new BusinessException('请先启用该语言，再设为默认');
        }

        DB::transaction(function () use ($lang) {
            Language::query()->update(['is_default' => 0]);
            $lang->update(['is_default' => 1]);
        });
        $this->locale->flush();
    }

    public function delete(int $id): void
    {
        $lang = $this->find($id);
        if ((int) $lang->is_default === 1) {
            throw new BusinessException('默认语言不能删除');
        }
        foreach (self::TRANSLATION_TABLES as $table) {
            if (DB::table($table)->where('lang', $lang->code)->exists()) {
                throw new BusinessException('该语言已有翻译数据，不能删除，请改为禁用');
            }
        }

        $lang->delete();
        $this->locale->flush();
    }

    private function find(int $id): Language
    {
        return Language::query()->find($id) ?? throw new BusinessException('语言不存在');
    }
}
