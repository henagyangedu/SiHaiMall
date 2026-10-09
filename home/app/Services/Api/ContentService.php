<?php

namespace App\Services\Api;

use App\Models\Article;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/** C 端内容：文章 / 公告 / 帮助中心、广告位。只返回当前对外可见的内容，文本按语言解析（缺失时回退默认语言） */
class ContentService
{
    public function __construct(private TranslationService $trans) {}

    /** 某类型下启用的分类 */
    public function categories(int $type, string $lang): array
    {
        $rows = DB::table('article_category')->where('type', $type)->where('status', 1)->whereNull('deleted_at')->orderBy('sort')->orderBy('id')->get(['id']);
        $names = $this->trans->fetch('article_category_lang', 'category_id', $rows->pluck('id')->all(), ['name']);

        return $rows->map(fn ($c) => ['id' => (int) $c->id, 'name' => $this->trans->resolve($names[$c->id]['name'] ?? [], $lang)])->all();
    }

    /** @param array{category_id?:int,page?:int,size?:int} $f */
    public function articles(int $type, array $f, string $lang): array
    {
        $p = $this->visible($type)
            ->when(!empty($f['category_id']), fn ($q) => $q->where('category_id', (int) $f['category_id']))
            ->orderByDesc('is_top')->orderBy('sort')->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(min(max((int) ($f['size'] ?? 10), 1), 50), ['id', 'category_id', 'cover', 'author', 'is_top', 'views', 'published_at'], 'page', max((int) ($f['page'] ?? 1), 1));

        $texts = $this->trans->fetch('article_lang', 'article_id', collect($p->items())->pluck('id')->all(), ['title', 'summary']);
        $p->through(fn ($a) => [
            'id' => (int) $a->id, 'category_id' => (int) $a->category_id,
            'title' => $this->trans->resolve($texts[$a->id]['title'] ?? [], $lang), 'summary' => $this->trans->resolve($texts[$a->id]['summary'] ?? [], $lang),
            'cover' => $a->cover, 'author' => $a->author, 'is_top' => (int) $a->is_top === 1, 'views' => (int) $a->views,
            'published_at' => (string) $a->published_at,
        ]);

        return Paginate::format($p);
    }

    /** 详情，同时浏览量 +1 */
    public function article(int $id, string $lang): array
    {
        $a = DB::table('article')->where('id', $id)->where('status', 1)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))->first()
            ?? throw new BusinessException('内容不存在或已下线');
        DB::table('article')->where('id', $id)->increment('views');

        $t = $this->trans->fetch('article_lang', 'article_id', [$id], ['title', 'summary', 'content', 'seo_title', 'seo_desc'])[$id] ?? [];
        $r = fn (string $f) => $this->trans->resolve($t[$f] ?? [], $lang);

        return [
            'id' => (int) $a->id, 'type' => array_search((int) $a->type, Article::TYPE_CODES, true), 'category_id' => (int) $a->category_id,
            'title' => $r('title'), 'summary' => $r('summary'), 'content' => $r('content'),
            'seo_title' => $r('seo_title') ?: $r('title'), 'seo_desc' => $r('seo_desc') ?: $r('summary'),
            'cover' => $a->cover, 'author' => $a->author, 'views' => (int) $a->views + 1, 'published_at' => (string) $a->published_at,
        ];
    }

    /** 帮助中心：分类 + 每个分类下的文章标题（一次取完，帮助内容通常不多） */
    public function help(string $lang): array
    {
        $cats = $this->categories(Article::TYPE_HELP, $lang);
        $rows = $this->visible(Article::TYPE_HELP)->whereIn('category_id', array_column($cats, 'id'))
            ->orderByDesc('is_top')->orderBy('sort')->orderBy('id')->limit(500)->get(['id', 'category_id']);
        $titles = $this->trans->fetch('article_lang', 'article_id', $rows->pluck('id')->all(), ['title']);

        return array_values(array_filter(array_map(function ($c) use ($rows, $titles, $lang) {
            $c['articles'] = $rows->where('category_id', $c['id'])->map(fn ($a) => [
                'id' => (int) $a->id, 'title' => $this->trans->resolve($titles[$a->id]['title'] ?? [], $lang),
            ])->values()->all();

            return $c;
        }, $cats), fn ($c) => $c['articles'] !== []));
    }

    /**
     * 按编码取广告位下当前展示的广告，可一次取多个广告位。
     * @param string[] $codes
     * @return array<string,array<int,array<string,string>>> 广告位编码 => 广告列表
     */
    public function ads(array $codes, string $lang): array
    {
        $codes = array_slice(array_values(array_unique(array_filter(array_map('trim', $codes)))), 0, 20);
        $positions = DB::table('ad_position')->whereIn('code', $codes)->where('status', 1)->pluck('code', 'id');
        $out = array_fill_keys($codes, []);
        if ($positions->isEmpty()) {
            return $out;
        }

        $rows = DB::table('ad')->whereIn('position_id', $positions->keys())->where('status', 1)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('start_at')->orWhere('start_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>', now()))
            ->orderBy('sort')->orderBy('id')->get();
        $texts = $this->trans->fetch('ad_lang', 'ad_id', $rows->pluck('id')->all(), ['title', 'image']);

        foreach ($rows as $a) {
            $image = $this->trans->resolve($texts[$a->id]['image'] ?? [], $lang);
            if ($image === '') {
                continue;
            }
            $out[$positions[$a->position_id]][] = [
                'id' => (int) $a->id, 'title' => $this->trans->resolve($texts[$a->id]['title'] ?? [], $lang), 'image' => $image,
                'link_type' => $a->link_type, 'link_value' => $a->link_value,
            ];
        }

        return $out;
    }

    private function visible(int $type)
    {
        return DB::table('article')->where('type', $type)->where('status', 1)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }
}
