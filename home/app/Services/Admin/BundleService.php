<?php

namespace App\Services\Admin;

use App\Models\Bundle;
use App\Services\Api\CatalogService;
use App\Services\Common\BundleService as BundleRules;
use App\Services\Common\LocaleService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/**
 * 后台的搭配套餐管理：新建、修改、启用 / 停用、删除。
 * 套餐绑定的是具体规格；立减金额必须小于这组商品现在的售价合计（按基准币种的标准价）。
 * 怎么算优惠在 Common\BundleService，这里不算钱，只在列表里展示“原价合计 / 套餐价”。
 */
class BundleService
{
    public function __construct(private CatalogService $catalog, private LocaleService $locale) {}

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));
        $p = Bundle::query()
            ->when($kw !== '', fn ($q) => $q->where('name', 'like', '%' . addcslashes($kw, '%_\\') . '%'))
            ->when(($f['status'] ?? '') !== '' && $f['status'] !== null, fn ($q) => $q->where('status', (int) $f['status']))
            ->orderByDesc('id')->paginate($size);

        $items = DB::table('bundle_item')->whereIn('bundle_id', collect($p->items())->pluck('id')->all())->orderBy('sort')->orderBy('id')->get(['bundle_id', 'sku_id'])->groupBy('bundle_id');
        $briefs = $this->briefs($items->flatten(1)->pluck('sku_id')->map(fn ($v) => (int) $v)->unique()->values()->all());

        $p->through(function (Bundle $b) use ($items, $briefs) {
            $rows = [];
            $total = '0.00';
            $problem = '';
            foreach (($items->get($b->id) ?? collect()) as $it) {
                $s = $briefs[(int) $it->sku_id] ?? null;
                // 商品被删掉了：这个套餐凑不齐，买家端不会再显示
                $rows[] = $s
                    ? ['sku_id' => $s['sku_id'], 'goods_id' => $s['goods_id'], 'title' => $s['title'], 'spec_text' => $s['spec_text'], 'image' => $s['image'], 'price' => $s['price'], 'stock' => $s['stock'], 'valid' => $s['valid']]
                    : ['sku_id' => (int) $it->sku_id, 'goods_id' => 0, 'title' => '（商品已删除）', 'spec_text' => '', 'image' => '', 'price' => '0.00', 'stock' => 0, 'valid' => false];
                $total = bcadd($total, $s['price'] ?? '0.00', 2);
                if (!$s || !$s['valid']) {
                    $problem = '有商品已下架或删除，买家端不显示这个套餐';
                } elseif ($problem === '' && $s['stock'] <= 0) {
                    $problem = '有商品缺货，买家端暂时不显示这个套餐';
                }
            }
            $now = now();

            return [
                'id' => (int) $b->id, 'name' => (string) $b->name, 'reduce_amount' => (string) $b->reduce_amount,
                'start_at' => $b->start_at?->format('Y-m-d H:i:s'), 'end_at' => $b->end_at?->format('Y-m-d H:i:s'),
                'status' => (int) $b->status, 'sort' => (int) $b->sort,
                // 时间上的状态：没开始 / 进行中 / 已结束（和启用开关是两回事）
                'period' => $b->start_at && $b->start_at->gt($now) ? 'pending' : ($b->end_at && $b->end_at->lt($now) ? 'ended' : 'running'),
                'items' => $rows, 'total' => $total, 'bundle_price' => bcsub($total, BundleRules::perSet((string) $b->reduce_amount, array_column($rows, 'price')), 2),
                'problem' => $problem,
            ];
        });

        return Paginate::format($p);
    }

    /** 新建 / 编辑时选商品用：按商品名称找在售商品，把它们的规格平铺出来 */
    public function skuOptions(string $keyword): array
    {
        $kw = trim($keyword);
        $goodsIds = DB::table('goods as g')->join('goods_lang as l', fn ($j) => $j->on('l.goods_id', '=', 'g.id')->where('l.lang', $this->locale->defaultLang()))
            ->whereNull('g.deleted_at')->where('g.is_on_sale', 1)
            ->when($kw !== '', fn ($q) => $q->where(fn ($w) => $w->where('l.title', 'like', '%' . addcslashes($kw, '%_\\') . '%')->orWhere('g.goods_sn', $kw)))
            ->orderByDesc('g.id')->limit(20)->pluck('g.id')->all();
        $skuIds = DB::table('goods_sku')->whereIn('goods_id', $goodsIds)->where('status', 1)->orderBy('goods_id')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();

        return array_values(array_map(fn ($s) => array_intersect_key($s, array_flip(['sku_id', 'goods_id', 'title', 'spec_text', 'image', 'price', 'stock'])), $this->briefs($skuIds)));
    }

    public function create(array $d): Bundle
    {
        [$fields, $skuIds] = $this->prepare($d);

        return DB::transaction(function () use ($fields, $skuIds) {
            $b = Bundle::query()->create($fields);
            $this->saveItems((int) $b->id, $skuIds);

            return $b;
        });
    }

    public function update(int $id, array $d): void
    {
        $b = $this->find($id);
        [$fields, $skuIds] = $this->prepare($d);
        DB::transaction(function () use ($b, $fields, $skuIds) {
            $b->update($fields);
            DB::table('bundle_item')->where('bundle_id', $b->id)->delete();
            $this->saveItems((int) $b->id, $skuIds);
        });
    }

    public function setStatus(int $id, int $status): void
    {
        $this->find($id)->update(['status' => $status === 1 ? 1 : 0]);
    }

    /** 删除只影响之后的订单；已经下的订单上记的是优惠金额和快照，不受影响 */
    public function delete(int $id): void
    {
        $this->find($id)->delete();
    }

    /** @return array{0:array<string,mixed>,1:int[]} [套餐字段, 规格 ID（按提交的顺序）] */
    private function prepare(array $d): array
    {
        $skuIds = array_values(array_unique(array_map('intval', (array) ($d['sku_ids'] ?? []))));
        if (count($skuIds) < Bundle::MIN_ITEMS || count($skuIds) > Bundle::MAX_ITEMS) {
            throw new BusinessException('一个套餐要选 ' . Bundle::MIN_ITEMS . ' 到 ' . Bundle::MAX_ITEMS . ' 个不同的商品规格');
        }
        $briefs = $this->briefs($skuIds);
        $total = '0.00';
        foreach ($skuIds as $id) {
            if (!isset($briefs[$id]) || !$briefs[$id]['valid']) {
                throw new BusinessException('选择的商品里有已下架或不存在的，请重新选择');
            }
            $total = bcadd($total, $briefs[$id]['price'], 2);
        }
        $reduce = bcadd((string) $d['reduce_amount'], '0', 2);
        if (bccomp($reduce, '0', 2) <= 0) {
            throw new BusinessException('立减金额必须大于 0');
        }
        if (bccomp($reduce, $total, 2) >= 0) {
            throw new BusinessException("立减金额必须小于这组商品的售价合计（{$total}）");
        }
        $start = $d['start_at'] ?? null;
        $end = $d['end_at'] ?? null;
        if ($start && $end && strtotime((string) $end) <= strtotime((string) $start)) {
            throw new BusinessException('结束时间必须晚于开始时间');
        }

        return [[
            'name' => trim((string) $d['name']), 'reduce_amount' => $reduce, 'start_at' => $start ?: null, 'end_at' => $end ?: null,
            'status' => (int) ($d['status'] ?? 1) === 1 ? 1 : 0, 'sort' => (int) ($d['sort'] ?? 0),
        ], $skuIds];
    }

    private function saveItems(int $bundleId, array $skuIds): void
    {
        $goods = DB::table('goods_sku')->whereIn('id', $skuIds)->pluck('goods_id', 'id');
        DB::table('bundle_item')->insert(array_map(fn ($id, $i) => ['bundle_id' => $bundleId, 'goods_id' => (int) $goods[$id], 'sku_id' => $id, 'sort' => $i], $skuIds, array_keys($skuIds)));
    }

    /** 规格的名称、图片和基准币种的标准价（后台看到的都是这个价，不带区域价） */
    private function briefs(array $skuIds): array
    {
        return $this->catalog->skuBriefs($skuIds, $this->locale->defaultLang(), $this->locale->baseCurrency());
    }

    private function find(int $id): Bundle
    {
        return Bundle::query()->find($id) ?? throw new BusinessException('套餐不存在');
    }
}
