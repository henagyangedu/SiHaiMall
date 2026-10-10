<?php

namespace App\Services\Api;

use App\Services\Common\LocaleService;
use App\Services\Common\PriceService;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/** 商品浏览：分类树、商品列表、商品详情，以及供购物车复用的 SKU 简要信息 */
class CatalogService
{
    public function __construct(
        private TranslationService $trans,
        private PriceService $price,
        private LocaleService $locale,
    ) {}

    // ------------------------------------------------------------------ 分类
    public function categories(string $lang): array
    {
        $rows = DB::table('category')->where('status', 1)->whereNull('deleted_at')->orderBy('sort')->orderBy('id')->get();
        $names = $this->trans->fetch('category_lang', 'category_id', $rows->pluck('id')->all(), ['name']);

        $byPid = [];
        foreach ($rows as $r) {
            $byPid[(int) $r->pid][] = [
                'id' => (int) $r->id, 'name' => $this->trans->resolve($names[$r->id]['name'] ?? [], $lang), 'image' => $r->image,
            ];
        }
        $build = function (int $pid) use (&$build, $byPid) {
            return array_map(function ($n) use (&$build) {
                $n['children'] = $build($n['id']);

                return $n;
            }, $byPid[$pid] ?? []);
        };

        return $build(0);
    }

    // ------------------------------------------------------------------ 列表
    /**
     * @param array{category_id?:int,keyword?:string,sort?:string,page?:int,size?:int} $f
     * @param array{id:int,name:string,currency:string}|null $region 展示价适用的销售区域（会员默认收货地址所在区域）
     */
    public function goodsList(array $f, string $lang, string $currency, ?array $region = null): array
    {
        $size = min(max((int) ($f['size'] ?? 20), 1), 50);

        $q = DB::table('goods as g')->whereNull('g.deleted_at')->where('g.is_on_sale', 1);

        if (!empty($f['category_id'])) {
            $q->whereIn('g.category_id', $this->categoryWithChildren((int) $f['category_id']));
        }
        // 只取指定的商品（内部用，如“我的收藏”；控制器不接收这个参数）
        if (isset($f['ids'])) {
            $q->whereIn('g.id', array_map('intval', (array) $f['ids']));
        }
        $kw = trim((string) ($f['keyword'] ?? ''));
        if ($kw !== '') {
            $like = '%' . addcslashes($kw, '%_\\') . '%';
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('goods_lang as l')->whereColumn('l.goods_id', 'g.id')
                ->where(fn ($w) => $w->where('l.title', 'like', $like)->orWhere('l.keywords', 'like', $like)));
        }

        // 价格排序按基准币种展示价，币种单独定价或有区域价的商品可能与实际展示价排序略有出入（列表页可接受）
        match ($f['sort'] ?? '') {
            'sales' => $q->orderByDesc('g.sales'),
            'price_asc' => $q->orderBy('g.price'),
            'price_desc' => $q->orderByDesc('g.price'),
            'new' => $q->orderByDesc('g.id'),
            default => $q->orderByDesc('g.sort')->orderByDesc('g.id'),
        };

        $p = $q->select('g.id', 'g.cover', 'g.price', 'g.market_price', 'g.sales', 'g.virtual_sales', 'g.stock', 'g.unit', 'g.spec_type')
            ->paginate($size, ['*'], 'page', max((int) ($f['page'] ?? 1), 1));

        $ids = collect($p->items())->pluck('id')->all();
        $titles = $this->trans->fetch('goods_lang', 'goods_id', $ids, ['title', 'subtitle']);
        $from = $this->minPrices($ids, $currency, $region);
        $cur = $this->price->currency($currency);

        $list = collect($p->items())->map(fn ($g) => [
            'id' => (int) $g->id,
            'title' => $this->trans->resolve($titles[$g->id]['title'] ?? [], $lang),
            'subtitle' => $this->trans->resolve($titles[$g->id]['subtitle'] ?? [], $lang),
            'cover' => $g->cover, 'unit' => $g->unit, 'multi_spec' => (int) $g->spec_type === 2,
            'price' => $from[$g->id] ?? '0.00', 'currency' => $cur['code'], 'symbol' => $cur['symbol'],
            'sales' => (int) $g->sales + (int) $g->virtual_sales, 'sold_out' => (int) $g->stock <= 0,
        ])->all();

        $p->setCollection(collect($list));

        return Paginate::format($p);
    }

    // ------------------------------------------------------------------ 详情
    public function goodsDetail(int $id, string $lang, string $currency, ?array $region = null): array
    {
        $g = DB::table('goods')->where('id', $id)->whereNull('deleted_at')->where('is_on_sale', 1)->first()
            ?? throw new BusinessException('商品不存在或已下架');

        $t = $this->trans->fetch('goods_lang', 'goods_id', [$id], ['title', 'subtitle', 'description', 'seo_title', 'seo_desc'])[$id] ?? [];
        $cur = $this->price->currency($currency);

        $skus = DB::table('goods_sku')->where('goods_id', $id)->where('status', 1)->orderBy('id')->get();
        $unit = $this->price->unitPrices($skus->pluck('price', 'id')->all(), $currency, $region);

        // 规格 → 规格值
        $specs = DB::table('goods_spec')->where('goods_id', $id)->orderBy('sort')->orderBy('id')->get();
        $values = DB::table('goods_spec_value')->where('goods_id', $id)->orderBy('sort')->orderBy('id')->get();
        $specNames = $this->trans->fetch('goods_spec_lang', 'spec_id', $specs->pluck('id')->all(), ['name']);
        $valNames = $this->trans->fetch('goods_spec_value_lang', 'value_id', $values->pluck('id')->all(), ['name']);

        $specOut = $specs->map(fn ($s) => [
            'id' => (int) $s->id,
            'name' => $this->trans->resolve($specNames[$s->id]['name'] ?? [], $lang),
            'values' => $values->where('spec_id', $s->id)->map(fn ($v) => [
                'id' => (int) $v->id, 'name' => $this->trans->resolve($valNames[$v->id]['name'] ?? [], $lang), 'image' => $v->image,
            ])->values()->all(),
        ])->values()->all();

        $images = json_decode((string) $g->images, true);

        return [
            'id' => (int) $g->id, 'category_id' => (int) $g->category_id,
            'title' => $this->trans->resolve($t['title'] ?? [], $lang),
            'subtitle' => $this->trans->resolve($t['subtitle'] ?? [], $lang),
            'description' => $this->trans->resolve($t['description'] ?? [], $lang),
            'seo_title' => $this->trans->resolve($t['seo_title'] ?? [], $lang),
            'seo_desc' => $this->trans->resolve($t['seo_desc'] ?? [], $lang),
            'cover' => $g->cover, 'images' => is_array($images) ? array_values($images) : [], 'video' => $g->video, 'unit' => $g->unit,
            'is_virtual' => (int) $g->is_virtual === 1, 'multi_spec' => (int) $g->spec_type === 2,
            'sales' => (int) $g->sales + (int) $g->virtual_sales,
            'currency' => $cur['code'], 'symbol' => $cur['symbol'],
            'specs' => $specOut,
            // 不返回成本价
            'skus' => $skus->map(fn ($s) => [
                'id' => (int) $s->id, 'spec_value_ids' => array_values(array_filter(array_map('intval', explode(',', (string) $s->spec_value_ids)))),
                'image' => $s->image ?: $g->cover, 'price' => $unit[$s->id],
                'stock' => (int) $s->stock, 'weight' => (string) $s->weight,
            ])->all(),
        ];
    }

    // ------------------------------------------------------------------ 供购物车 / 结算复用
    /**
     * SKU 简要信息（含当前币种单价与可购状态）。不存在的 SKU 不出现在结果里。传入 $region 时单价按该销售区域取价。
     * @param int[] $skuIds
     * @return array<int,array{sku_id:int,goods_id:int,title:string,spec_text:string,image:string,unit:string,price:string,stock:int,valid:bool,is_virtual:bool}>
     */
    public function skuBriefs(array $skuIds, string $lang, string $currency, ?array $region = null): array
    {
        if ($skuIds === []) {
            return [];
        }
        $rows = DB::table('goods_sku as s')->join('goods as g', 'g.id', '=', 's.goods_id')
            ->whereIn('s.id', $skuIds)->whereNull('g.deleted_at')
            ->select('s.id', 's.goods_id', 's.spec_value_ids', 's.image', 's.price', 's.stock', 's.status', 'g.cover', 'g.unit', 'g.is_on_sale', 'g.is_virtual')
            ->get()->keyBy('id');

        $titles = $this->trans->fetch('goods_lang', 'goods_id', $rows->pluck('goods_id')->unique()->all(), ['title']);
        $valueIds = [];
        foreach ($rows as $r) {
            foreach (array_filter(explode(',', (string) $r->spec_value_ids)) as $v) {
                $valueIds[(int) $v] = true;
            }
        }
        $valNames = $this->trans->fetch('goods_spec_value_lang', 'value_id', array_keys($valueIds), ['name']);
        $unit = $this->price->unitPrices($rows->pluck('price', 'id')->all(), $currency, $region);

        $out = [];
        foreach ($rows as $id => $r) {
            $parts = [];
            foreach (array_filter(explode(',', (string) $r->spec_value_ids)) as $v) {
                $parts[] = $this->trans->resolve($valNames[(int) $v]['name'] ?? [], $lang);
            }
            $out[(int) $id] = [
                'sku_id' => (int) $id, 'goods_id' => (int) $r->goods_id,
                'title' => $this->trans->resolve($titles[$r->goods_id]['title'] ?? [], $lang),
                'spec_text' => implode(' / ', array_filter($parts)),
                'image' => $r->image ?: $r->cover, 'unit' => $r->unit,
                'price' => $unit[$id], 'stock' => (int) $r->stock,
                'valid' => (int) $r->status === 1 && (int) $r->is_on_sale === 1,
                'is_virtual' => (int) $r->is_virtual === 1,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ 内部
    /** @return int[] */
    private function categoryWithChildren(int $id): array
    {
        $ids = [$id];
        $cur = [$id];
        for ($depth = 0; $depth < 5 && $cur !== []; $depth++) {
            $cur = DB::table('category')->whereIn('pid', $cur)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();
            $ids = array_merge($ids, $cur);
        }

        return $ids;
    }

    /** 每个商品的起售价（当前币种，取有效 SKU 最低价） @return array<int,string> */
    private function minPrices(array $goodsIds, string $currency, ?array $region = null): array
    {
        if ($goodsIds === []) {
            return [];
        }
        $skus = DB::table('goods_sku')->whereIn('goods_id', $goodsIds)->where('status', 1)->get(['id', 'goods_id', 'price']);
        $unit = $this->price->unitPrices($skus->pluck('price', 'id')->all(), $currency, $region);

        $min = [];
        foreach ($skus as $s) {
            $p = $unit[$s->id];
            if (!isset($min[$s->goods_id]) || bccomp($p, $min[$s->goods_id], 2) < 0) {
                $min[(int) $s->goods_id] = $p;
            }
        }

        return $min;
    }
}
