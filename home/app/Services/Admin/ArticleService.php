<?php

namespace App\Services\Admin;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Services\Common\LocaleService;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/**
 * 内容管理：文章（type=1）、公告（2）、帮助中心（3）共用一套结构，$type 由路由决定。
 * 文章和帮助有一级分类，公告不分类。对外可见 = 已发布 且 发布时间为空或已到。
 */
class ArticleService
{
    private const LANG_FIELDS = ['title', 'summary', 'content', 'seo_title', 'seo_desc'];

    public function __construct(private TranslationService $trans, private LocaleService $locale) {}

    // ------------------------------------------------------------------ 分类
    public function categories(int $type): array
    {
        $this->assertHasCategory($type);
        $rows = ArticleCategory::query()->where('type', $type)->orderBy('sort')->orderBy('id')->get();
        $names = $this->trans->fetch('article_category_lang', 'category_id', $rows->pluck('id')->all(), ['name']);
        $counts = DB::table('article')->whereIn('category_id', $rows->pluck('id'))->whereNull('deleted_at')
            ->groupBy('category_id')->selectRaw('category_id, count(*) as c')->pluck('c', 'category_id');

        return $rows->map(fn (ArticleCategory $c) => [
            'id' => (int) $c->id, 'name' => $names[$c->id]['name'] ?? [], 'name_text' => $this->trans->resolve($names[$c->id]['name'] ?? []),
            'sort' => (int) $c->sort, 'status' => (int) $c->status, 'article_count' => (int) ($counts[$c->id] ?? 0),
        ])->all();
    }

    public function saveCategory(int $type, int $id, array $d): ArticleCategory
    {
        $this->assertHasCategory($type);
        $name = (array) ($d['name'] ?? []);
        $this->trans->assertDefaultFilled($name, '分类名称');

        return DB::transaction(function () use ($type, $id, $d, $name) {
            $fields = ['sort' => (int) ($d['sort'] ?? 0), 'status' => (int) ($d['status'] ?? 1)];
            $cat = $id > 0 ? $this->findCategory($type, $id) : new ArticleCategory(['type' => $type]);
            $cat->fill($fields)->save();
            $this->trans->sync('article_category_lang', 'category_id', (int) $cat->id, ['name' => $name], ['name']);

            return $cat;
        });
    }

    public function deleteCategory(int $type, int $id): void
    {
        $cat = $this->findCategory($type, $id);
        $n = Article::query()->where('category_id', $id)->count();
        if ($n > 0) {
            throw new BusinessException("该分类下还有 {$n} 篇内容，不能删除");
        }
        $cat->delete();
    }

    // ------------------------------------------------------------------ 内容
    public function list(int $type, array $f): array
    {
        $kw = trim((string) ($f['keyword'] ?? ''));
        $p = Article::query()->where('type', $type)
            ->when($kw !== '', fn ($q) => $q->whereIn('id', DB::table('article_lang')->where('title', 'like', '%' . addcslashes($kw, '%_\\') . '%')->select('article_id')))
            ->when(!empty($f['category_id']), fn ($q) => $q->where('category_id', (int) $f['category_id']))
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('status', (int) $f['status']))
            ->orderByDesc('is_top')->orderBy('sort')->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(min(max((int) ($f['size'] ?? 15), 1), 100));

        $rows = collect($p->items());
        $titles = $this->trans->fetch('article_lang', 'article_id', $rows->pluck('id')->all(), ['title']);
        $cats = $this->trans->fetch('article_category_lang', 'category_id', $rows->pluck('category_id')->filter()->unique()->all(), ['name']);

        $p->through(fn (Article $a) => [
            'id' => (int) $a->id, 'title' => $this->trans->resolve($titles[$a->id]['title'] ?? []),
            'langs' => array_keys(array_filter($titles[$a->id]['title'] ?? [], fn ($t) => trim($t) !== '')),
            'category_id' => (int) $a->category_id, 'category_name' => $this->trans->resolve($cats[$a->category_id]['name'] ?? []),
            'cover' => $a->cover, 'author' => $a->author, 'is_top' => (int) $a->is_top, 'sort' => (int) $a->sort,
            'status' => (int) $a->status, 'views' => (int) $a->views,
            // 已发布但还没到发布时间：前台暂时看不到
            'scheduled' => (int) $a->status === 1 && $a->published_at && $a->published_at->isFuture(),
            'published_at' => $a->published_at?->format('Y-m-d H:i:s'), 'updated_at' => $a->updated_at?->format('Y-m-d H:i:s'),
        ]);

        return Paginate::format($p);
    }

    public function detail(int $type, int $id): array
    {
        $a = $this->find($type, $id);
        $texts = $this->trans->fetch('article_lang', 'article_id', [$id], self::LANG_FIELDS)[$id] ?? [];

        $out = [
            'id' => (int) $a->id, 'category_id' => (int) $a->category_id, 'cover' => $a->cover, 'author' => $a->author,
            'is_top' => (int) $a->is_top, 'sort' => (int) $a->sort, 'status' => (int) $a->status,
            'published_at' => $a->published_at?->format('Y-m-d H:i:s'),
        ];
        foreach (self::LANG_FIELDS as $f) {
            $out[$f] = $texts[$f] ?? [];
        }

        return $out;
    }

    public function save(int $type, int $id, array $d): Article
    {
        $title = (array) ($d['title'] ?? []);
        $this->trans->assertDefaultFilled($title, '标题');
        $this->trans->assertDefaultFilled((array) ($d['content'] ?? []), '正文');

        $categoryId = in_array($type, Article::WITH_CATEGORY, true) ? (int) ($d['category_id'] ?? 0) : 0;
        if ($categoryId > 0) {
            $this->findCategory($type, $categoryId);
        } elseif ($type === Article::TYPE_HELP) {
            throw new BusinessException('请选择帮助分类');
        }

        return DB::transaction(function () use ($type, $id, $d, $categoryId) {
            $a = $id > 0 ? $this->find($type, $id) : new Article(['type' => $type]);
            $status = (int) ($d['status'] ?? 1);
            $a->fill([
                'category_id' => $categoryId, 'cover' => (string) ($d['cover'] ?? ''), 'author' => trim((string) ($d['author'] ?? '')),
                'is_top' => (int) ($d['is_top'] ?? 0), 'sort' => (int) ($d['sort'] ?? 0), 'status' => $status,
                // 没填发布时间：发布时记为现在；草稿保持为空
                'published_at' => !empty($d['published_at']) ? $d['published_at'] : ($status === 1 ? ($a->published_at ?? now()) : null),
            ])->save();

            // 每个启用的语言都提供全部字段（标题列没有默认值）；标题为空的语言正文也不保存
            $data = [];
            foreach (self::LANG_FIELDS as $f) {
                foreach ($this->locale->codes() as $code) {
                    $hasTitle = trim((string) ($d['title'][$code] ?? '')) !== '';
                    $data[$f][$code] = $hasTitle ? (string) ($d[$f][$code] ?? '') : '';
                }
            }
            $this->trans->sync('article_lang', 'article_id', (int) $a->id, $data, self::LANG_FIELDS);

            return $a;
        });
    }

    public function setStatus(int $type, int $id, int $status): void
    {
        $a = $this->find($type, $id);
        $a->update(['status' => $status, 'published_at' => $status === 1 ? ($a->published_at ?? now()) : $a->published_at]);
    }

    public function setTop(int $type, int $id, int $top): void
    {
        $this->find($type, $id)->update(['is_top' => $top]);
    }

    public function delete(int $type, int $id): void
    {
        $this->find($type, $id)->delete();
    }

    private function find(int $type, int $id): Article
    {
        return Article::query()->where('type', $type)->find($id) ?? throw new BusinessException(Article::TYPE_TEXT[$type] . '不存在');
    }

    private function findCategory(int $type, int $id): ArticleCategory
    {
        return ArticleCategory::query()->where('type', $type)->find($id) ?? throw new BusinessException('分类不存在');
    }

    private function assertHasCategory(int $type): void
    {
        if (!in_array($type, Article::WITH_CATEGORY, true)) {
            throw new BusinessException('公告没有分类');
        }
    }
}
