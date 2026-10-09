<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Goods;
use App\Models\GoodsSku;
use App\Models\ShippingTemplate;
use App\Services\Common\LocaleService;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/**
 * 商品管理：单规格 + 多规格。规格与 SKU 的细节在 GoodsSkuService。
 * 价格口径：sku.price 为基准币种价格；其它币种可在 gm_goods_price 单独定价，未定价则按汇率折算。
 * 销售区域价存 gm_goods_region_price（区域的定价币种），收货地址命中区域且有区域价时优先使用。
 * 库存只能在新增（含新增规格组合）时设置初始值，之后一律通过 adjustStock 调整并写库存流水。
 * 规格类型（单/多）创建后不可更改，避免库存和订单历史错乱。
 */
class GoodsService
{
    private const LANG_FIELDS = ['title', 'subtitle', 'keywords', 'description', 'seo_title', 'seo_desc'];
    private const MAX_IMAGES = 9;

    public function __construct(
        private TranslationService $trans,
        private LocaleService $locale,
        private GoodsSkuService $skus,
    ) {}

    // ------------------------------------------------------------------ 列表
    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));

        $paginator = Goods::query()
            ->when($kw !== '', function ($q) use ($kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->where(fn ($w) => $w->where('goods_sn', 'like', $like)
                    ->orWhereIn('id', DB::table('goods_lang')->where('title', 'like', $like)->select('goods_id'))
                    ->orWhereIn('id', DB::table('goods_sku')->where('sku_sn', 'like', $like)->select('goods_id')));
            })
            ->when(!empty($f['category_id']), fn ($q) => $q->whereIn('category_id', $this->categoryWithDescendants((int) $f['category_id'])))
            ->when(!empty($f['brand_id']), fn ($q) => $q->where('brand_id', (int) $f['brand_id']))
            ->when(isset($f['is_on_sale']) && $f['is_on_sale'] !== '', fn ($q) => $q->where('is_on_sale', (int) $f['is_on_sale']))
            ->when(!empty($f['low_stock']), function ($q) {
                $q->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))->from('goods_sku')
                        ->whereColumn('goods_sku.goods_id', 'goods.id')
                        ->where('goods_sku.status', 1)
                        ->where('goods_sku.warn_stock', '>', 0)
                        ->whereColumn('goods_sku.stock', '<=', 'goods_sku.warn_stock');
                });
            })
            ->orderBy('sort')->orderByDesc('id')
            ->paginate($size);

        $items = collect($paginator->items());
        $titles = $this->trans->fetch('goods_lang', 'goods_id', $items->pluck('id')->all(), ['title']);
        $cats = $this->trans->fetch('category_lang', 'category_id', $items->pluck('category_id')->unique()->all(), ['name']);
        $brands = $this->trans->fetch('brand_lang', 'brand_id', $items->pluck('brand_id')->unique()->filter()->all(), ['name']);
        $lowIds = DB::table('goods_sku')
            ->whereIn('goods_id', $items->pluck('id')->all())
            ->where('status', 1)
            ->where('warn_stock', '>', 0)
            ->whereColumn('stock', '<=', 'warn_stock')
            ->pluck('goods_id')->unique()->flip()->all();

        $paginator->through(fn (Goods $g) => [
            'id'            => (int) $g->id,
            'cover'         => $g->cover,
            'title'         => $this->trans->resolve($titles[$g->id]['title'] ?? []),
            'goods_sn'      => $g->goods_sn,
            'category_name' => $this->trans->resolve($cats[$g->category_id]['name'] ?? []),
            'brand_name'    => $this->trans->resolve($brands[$g->brand_id]['name'] ?? []),
            'price'         => (string) $g->price,
            'stock'         => (int) $g->stock,
            'low_stock'     => isset($lowIds[$g->id]),
            'sales'         => (int) $g->sales,
            'spec_type'     => (int) $g->spec_type,
            'is_on_sale'    => (int) $g->is_on_sale,
            'sort'          => (int) $g->sort,
            'created_at'    => $g->created_at?->format('Y-m-d H:i:s'),
        ]);

        return Paginate::format($paginator);
    }

    // ------------------------------------------------------------------ 详情
    /**
     * 分销佣金设置：0 跟随全局、1 单独设置比例、2 不参与分销。只有“单独设置”才保存比例，其余清零。
     * @return array{commission_type:int,commission_rate_1:string,commission_rate_2:string}
     */
    private function commissionFields(array $d): array
    {
        $type = (int) ($d['commission_type'] ?? 0);
        $rate = fn (string $key) => $type === 1 ? number_format((float) ($d[$key] ?? 0), 2, '.', '') : '0.00';

        return ['commission_type' => $type, 'commission_rate_1' => $rate('commission_rate_1'), 'commission_rate_2' => $rate('commission_rate_2')];
    }

    public function detail(int $id): array
    {
        $g = $this->find($id);
        $texts = $this->trans->fetch('goods_lang', 'goods_id', [$id], self::LANG_FIELDS)[$id] ?? [];

        $out = [
            'id'          => (int) $g->id,
            'category_id' => (int) $g->category_id,
            'brand_id'    => (int) $g->brand_id,
            'shipping_template_id' => (int) $g->shipping_template_id,
            'commission_type' => (int) $g->commission_type,
            'commission_rate_1' => (string) $g->commission_rate_1,
            'commission_rate_2' => (string) $g->commission_rate_2,
            'goods_sn'    => $g->goods_sn,
            'images'      => json_decode((string) ($g->images ?: '[]'), true) ?: [],
            'video'       => $g->video,
            'spec_type'   => (int) $g->spec_type,
            'unit'        => $g->unit,
            'is_virtual'  => (int) $g->is_virtual,
            'is_on_sale'  => (int) $g->is_on_sale,
            'sort'        => (int) $g->sort,
        ];
        foreach (self::LANG_FIELDS as $field) {
            $out[$field] = $texts[$field] ?? [];
        }

        return $out + $this->skus->load($id, (int) $g->spec_type);
    }

    // ------------------------------------------------------------------ 新增 / 编辑
    public function create(array $d, Admin $op): Goods
    {
        $this->assertPayload($d, null);
        $specType = (int) $d['spec_type'];

        return DB::transaction(function () use ($d, $op, $specType) {
            $images = $this->cleanImages($d['images'] ?? []);

            $goods = Goods::query()->create([
                'category_id' => (int) $d['category_id'],
                'brand_id'    => (int) ($d['brand_id'] ?? 0),
                'shipping_template_id' => (int) ($d['shipping_template_id'] ?? 0),
                ...$this->commissionFields($d),
                'goods_sn'    => trim((string) ($d['goods_sn'] ?? '')) ?: $this->generateSn(),
                'cover'       => $images[0] ?? '',
                'images'      => json_encode($images, JSON_UNESCAPED_SLASHES),
                'video'       => (string) ($d['video'] ?? ''),
                'spec_type'   => $specType,
                'unit'        => (string) ($d['unit'] ?? ''),
                'is_virtual'  => (int) ($d['is_virtual'] ?? 0),
                'is_on_sale'  => (int) ($d['is_on_sale'] ?? 0),
                'sort'        => (int) ($d['sort'] ?? 0),
            ]);

            $this->saveTexts((int) $goods->id, $d);
            $this->saveSkus($goods, $d, true, $op);

            return $goods;
        });
    }

    public function update(int $id, array $d, Admin $op): Goods
    {
        $goods = $this->find($id);
        if ((int) $d['spec_type'] !== (int) $goods->spec_type) {
            throw new BusinessException('规格类型创建后不能更改，如需更换请新建商品');
        }
        $this->assertPayload($d, $id);

        return DB::transaction(function () use ($goods, $id, $d, $op) {
            $images = $this->cleanImages($d['images'] ?? []);

            $goods->update([
                'category_id' => (int) $d['category_id'],
                'brand_id'    => (int) ($d['brand_id'] ?? 0),
                'shipping_template_id' => (int) ($d['shipping_template_id'] ?? 0),
                ...$this->commissionFields($d),
                'goods_sn'    => trim((string) ($d['goods_sn'] ?? '')) ?: $goods->goods_sn,
                'cover'       => $images[0] ?? '',
                'images'      => json_encode($images, JSON_UNESCAPED_SLASHES),
                'video'       => (string) ($d['video'] ?? ''),
                'unit'        => (string) ($d['unit'] ?? ''),
                'is_virtual'  => (int) ($d['is_virtual'] ?? 0),
                'is_on_sale'  => (int) ($d['is_on_sale'] ?? 0),
                'sort'        => (int) ($d['sort'] ?? 0),
            ]);

            $this->saveTexts($id, $d);
            $this->saveSkus($goods, $d, false, $op);

            return $goods;
        });
    }

    public function setSale(int $id, int $on): void
    {
        $goods = $this->find($id);
        if ($on === 1) {
            $images = json_decode((string) ($goods->images ?: '[]'), true) ?: [];
            if ($images === []) {
                throw new BusinessException('上架前请先上传商品图片');
            }
            if (!GoodsSku::query()->where('goods_id', $id)->where('status', 1)->exists()) {
                throw new BusinessException('上架前请至少启用一个规格');
            }
        }
        $goods->update(['is_on_sale' => $on]);
    }

    public function delete(int $id): void
    {
        $this->find($id)->delete(); // 软删除；订单里保存的是商品快照，不受影响
    }

    // ------------------------------------------------------------------ 库存调整
    /** 多规格商品必须指定 sku_id；单规格可省略 */
    public function adjustStock(int $goodsId, ?int $skuId, int $change, string $remark, Admin $op): int
    {
        if ($change === 0) {
            throw new BusinessException('调整数量不能为 0');
        }

        return DB::transaction(function () use ($goodsId, $skuId, $change, $remark, $op) {
            $this->find($goodsId);

            $found = GoodsSku::query()
                ->where('goods_id', $goodsId)
                ->when($skuId, fn ($q) => $q->where('id', $skuId))
                ->lockForUpdate()
                ->get();
            if ($found->count() !== 1) {
                throw new BusinessException('请指定要调整库存的规格');
            }

            $sku = $found->first();
            $new = (int) $sku->stock + $change;
            if ($new < 0) {
                throw new BusinessException('库存不足，当前库存 ' . (int) $sku->stock);
            }

            $sku->update(['stock' => $new]);
            $this->skus->refreshTotals($goodsId);
            $this->skus->logStock($goodsId, (int) $sku->id, $change, $new, $remark !== '' ? $remark : '后台调整', $op);

            return $new;
        });
    }

    // ------------------------------------------------------------------ 内部方法
    private function find(int $id): Goods
    {
        return Goods::query()->find($id) ?? throw new BusinessException('商品不存在');
    }

    private function saveSkus(Goods $goods, array $d, bool $create, Admin $op): void
    {
        if ((int) $d['spec_type'] === 1) {
            $this->skus->saveSingle($goods, (array) $d['sku'], (array) ($d['prices'] ?? []), (array) ($d['region_prices'] ?? []), $create, $op);
        } else {
            $this->skus->saveMulti($goods, $d, $create, $op);
        }
    }

    private function assertPayload(array $d, ?int $selfId): void
    {
        $specType = (int) ($d['spec_type'] ?? 1);
        if (!in_array($specType, [1, 2], true)) {
            throw new BusinessException('规格类型不正确');
        }

        $this->trans->assertDefaultFilled((array) ($d['title'] ?? []), '商品标题');

        if (!Category::query()->where('id', (int) $d['category_id'])->exists()) {
            throw new BusinessException('所选分类不存在');
        }
        $brandId = (int) ($d['brand_id'] ?? 0);
        if ($brandId > 0 && !Brand::query()->where('id', $brandId)->exists()) {
            throw new BusinessException('所选品牌不存在');
        }
        $templateId = (int) ($d['shipping_template_id'] ?? 0);
        if ($templateId > 0 && !ShippingTemplate::query()->where('id', $templateId)->exists()) {
            throw new BusinessException('所选运费模板不存在');
        }

        $images = $this->cleanImages($d['images'] ?? []);
        if (count($images) > self::MAX_IMAGES) {
            throw new BusinessException('商品图片最多 ' . self::MAX_IMAGES . ' 张');
        }
        if ((int) ($d['is_on_sale'] ?? 0) === 1 && $images === []) {
            throw new BusinessException('上架商品必须至少有一张图片');
        }

        $sn = trim((string) ($d['goods_sn'] ?? ''));
        if ($sn !== '') {
            $dup = Goods::query()->where('goods_sn', $sn)->when($selfId, fn ($q) => $q->where('id', '!=', $selfId))->exists();
            if ($dup) {
                throw new BusinessException('商品编码已存在：' . $sn);
            }
        }

        if ($specType === 2) {
            $this->skus->assertMulti($d);
        }
    }

    private function saveTexts(int $goodsId, array $d): void
    {
        $data = [];
        foreach (self::LANG_FIELDS as $f) {
            $data[$f] = (array) ($d[$f] ?? []);
        }
        $this->trans->sync('goods_lang', 'goods_id', $goodsId, $data, self::LANG_FIELDS);
    }

    private function cleanImages(mixed $images): array
    {
        return array_values(array_filter(array_map(fn ($u) => trim((string) $u), (array) $images), fn ($u) => $u !== ''));
    }

    private function generateSn(): string
    {
        do {
            $sn = 'G' . date('ymd') . random_int(1000, 9999);
        } while (Goods::withTrashed()->where('goods_sn', $sn)->exists());

        return $sn;
    }

    private function categoryWithDescendants(int $id): array
    {
        $pidMap = Category::query()->pluck('pid', 'id')->map(fn ($v) => (int) $v)->all();
        $ids = [$id];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($pidMap as $cid => $pid) {
                if (in_array($pid, $ids, true) && !in_array((int) $cid, $ids, true)) {
                    $ids[] = (int) $cid;
                    $changed = true;
                }
            }
        }

        return $ids;
    }
}
