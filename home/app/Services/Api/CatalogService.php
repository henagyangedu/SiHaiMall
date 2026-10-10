<?php

namespace App\Services\Api;

use App\Services\Common\BundleService;
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
        private BundleService $bundles,
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
        // 关键词：用空格分开的每个词都要命中（标题、副标题、搜索关键词里任意一处，任意一种语言），最多取 5 个词
        foreach (self::searchTerms((string) ($f['keyword'] ?? '')) as $term) {
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('goods_lang as l')->whereColumn('l.goods_id', 'g.id')
                ->where(fn ($w) => $w->where('l.title', 'like', $like)->orWhere('l.subtitle', 'like', $like)->orWhere('l.keywords', 'like', $like)));
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

    /** 把买家输入的搜索内容拆成词：按空白（含全角空格）分开，去重，最多 5 个，整体最多 50 个字 */
    public static function searchTerms(string $keyword): array
    {
        $parts = preg_split('/[\s\x{3000}]+/u', mb_substr(trim($keyword), 0, 50), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($parts)), 0, 5);
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
            // 规格参数；配套的耗材和配件；反过来，把这个商品设为配套的那些设备（“适用设备”）
            'params' => $this->params($id, $lang),
            'related' => $this->relatedGoods(DB::table('goods_relation')->where('goods_id', $id)->orderBy('sort')->orderBy('id')->limit(12)->pluck('related_goods_id')->all(), $lang, $currency, $region),
            'fits' => $this->relatedGoods(DB::table('goods_relation')->where('related_goods_id', $id)->orderByDesc('id')->limit(12)->pluck('goods_id')->all(), $lang, $currency, $region),
            // 包含这个商品的搭配套餐（只在基准币种下有；套餐里有下架或缺货的商品就不显示）
            'bundles' => $this->bundlesFor($id, $lang, $currency, $region),
            // 不返回成本价
            'skus' => $skus->map(fn ($s) => [
                'id' => (int) $s->id, 'spec_value_ids' => array_values(array_filter(array_map('intval', explode(',', (string) $s->spec_value_ids)))),
                'image' => $s->image ?: $g->cover, 'price' => $unit[$s->id],
                'stock' => (int) $s->stock, 'weight' => (string) $s->weight,
            ])->all(),
        ];
    }

    /** 商品的规格参数（当前语言，缺的回退默认语言）。@return array<int,array{name:string,value:string}> */
    /**
     * 商品详情页的“搭配套餐”：每个套餐列出里面的商品、原价合计、套餐价。
     * 这里只是展示，真正的优惠在结算时由 BundleService::quote 按整张订单算（买家同时凑了别的套餐时结果可能不同）。
     */
    private function bundlesFor(int $goodsId, string $lang, string $currency, ?array $region): array
    {
        if (strtoupper($currency) !== strtoupper($this->locale->baseCurrency())) {
            return [];
        }
        $list = $this->bundles->forGoods($goodsId);
        $briefs = $this->skuBriefs(array_values(array_unique(array_merge([], ...array_column($list, 'sku_ids')))), $lang, $currency, $region);

        $out = [];
        foreach ($list as $b) {
            $items = [];
            foreach ($b['sku_ids'] as $skuId) {
                $s = $briefs[$skuId] ?? null;
                if (!$s || !$s['valid'] || $s['stock'] <= 0) {
                    continue 2;
                }
                $items[] = ['sku_id' => $s['sku_id'], 'goods_id' => $s['goods_id'], 'title' => $s['title'], 'spec_text' => $s['spec_text'], 'image' => $s['image'], 'price' => $s['price']];
            }
            $total = array_reduce($items, fn ($c, $i) => bcadd($c, $i['price'], 2), '0.00');
            $reduce = BundleService::perSet($b['reduce_amount'], array_column($items, 'price'));
            if (bccomp($reduce, '0', 2) <= 0) {
                continue;
            }
            $out[] = ['id' => $b['id'], 'items' => $items, 'total' => $total, 'reduce' => $reduce, 'bundle_price' => bcsub($total, $reduce, 2)];
        }

        return array_slice($out, 0, 5);
    }

    private function params(int $goodsId, string $lang): array
    {
        $ids = DB::table('goods_param')->where('goods_id', $goodsId)->orderBy('sort')->orderBy('id')->pluck('id')->all();
        $texts = $this->trans->fetch('goods_param_lang', 'param_id', $ids, ['name', 'value']);
        $out = [];
        foreach ($ids as $id) {
            $name = $this->trans->resolve($texts[$id]['name'] ?? [], $lang);
            $value = $this->trans->resolve($texts[$id]['value'] ?? [], $lang);
            if ($name !== '' && $value !== '') {
                $out[] = ['name' => $name, 'value' => $value];
            }
        }

        return $out;
    }

    /** 按给定顺序取一组商品的卡片信息；下架、删除的自动不出现 */
    private function relatedGoods(array $ids, string $lang, string $currency, ?array $region): array
    {
        $ids = array_map('intval', $ids);
        if ($ids === []) {
            return [];
        }
        $rows = collect($this->goodsList(['ids' => $ids, 'size' => 50], $lang, $currency, $region)['list'])->keyBy('id');

        return array_values(array_filter(array_map(fn ($id) => $rows->get($id), $ids)));
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
            ->select('s.id', 's.goods_id', 's.spec_value_ids', 's.image', 's.price', 's.stock', 's.status', 'g.cover', 'g.unit', 'g.is_on_sale', 'g.is_virtual', 'g.category_id')
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
                'sku_id' => (int) $id, 'goods_id' => (int) $r->goods_id, 'category_id' => (int) $r->category_id,
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
