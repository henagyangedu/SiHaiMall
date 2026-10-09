<?php

namespace App\Services\Admin;

use App\Services\Common\LocaleService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/** 翻译术语表：品牌名、专有名词的固定译法，翻译时自动带入提示词 */
class GlossaryService
{
    public function __construct(private LocaleService $locale) {}

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 20), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));

        $paginator = DB::table('translate_glossary')
            ->when($kw !== '', function ($q) use ($kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->where(fn ($w) => $w->where('term', 'like', $like)->orWhere('translation', 'like', $like));
            })
            ->when(!empty($f['lang']), fn ($q) => $q->where('lang', $f['lang']))
            ->orderByDesc('id')
            ->paginate($size)
            ->through(fn ($r) => ['id' => (int) $r->id, 'term' => $r->term, 'lang' => $r->lang, 'translation' => $r->translation, 'created_at' => $r->created_at]);

        return Paginate::format($paginator);
    }

    public function create(array $d): int
    {
        $this->assertValid($d, null);

        return (int) DB::table('translate_glossary')->insertGetId([
            'term' => trim($d['term']), 'lang' => $d['lang'], 'translation' => trim($d['translation']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function update(int $id, array $d): void
    {
        $this->assertValid($d, $id);
        DB::table('translate_glossary')->where('id', $id)->update([
            'term' => trim($d['term']), 'lang' => $d['lang'], 'translation' => trim($d['translation']), 'updated_at' => now(),
        ]);
    }

    public function delete(int $id): void
    {
        DB::table('translate_glossary')->where('id', $id)->delete();
    }

    private function assertValid(array $d, ?int $selfId): void
    {
        if (!in_array($d['lang'], $this->locale->codes(), true)) {
            throw new BusinessException('目标语言不存在或未启用');
        }
        $dup = DB::table('translate_glossary')
            ->where('term', trim($d['term']))->where('lang', $d['lang'])
            ->when($selfId, fn ($q) => $q->where('id', '!=', $selfId))
            ->exists();
        if ($dup) {
            throw new BusinessException('该术语在此语言下已存在');
        }
    }
}
