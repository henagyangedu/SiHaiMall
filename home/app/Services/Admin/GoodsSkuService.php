<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\Goods;
use App\Models\GoodsSku;
use App\Services\Common\LocaleService;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * 商品规格与 SKU：单规格（一个 SKU）和多规格（规格项 → 规格值 → SKU 组合）。
 *
 * 多规格的载荷约定：
 *   specs: [{id?, name:{lang:text}, values:[{id?, key, name:{lang:text}}]}]
 *   skus : [{id?, value_keys:[key,...], sku_sn, image, price, ..., status, prices:{币种:价格}}]
 * value 的 key 由前端生成（新增的规格值还没有 ID），SKU 通过 key 引用规格值；已有 SKU 带 id，库存字段对已有 SKU 一律忽略。
 */
class GoodsSkuService
{
    private const MAX_SPECS = 3;
    private const MAX_SKUS = 200;

    public function __construct(private TranslationService $trans, private LocaleService $locale) {}

    // ------------------------------------------------------------------ 读取
    public function load(int $goodsId, int $specType): array
    {
        $skuRows = GoodsSku::query()->where('goods_id', $goodsId)->orderBy('id')->get();
        $prices = [];
        foreach (DB::table('goods_price')->whereIn('sku_id', $skuRows->pluck('id')->all())->get() as $p) {
            $prices[$p->sku_id][$p->currency] = (string) $p->price;
        }

        $fmt = fn (GoodsSku $s) => [
            'id'           => (int) $s->id,
            'sku_sn'       => $s->sku_sn,
            'image'        => $s->image,
            'price'        => (string) $s->price,
            'market_price' => (string) $s->market_price,
            'cost_price'   => (string) $s->cost_price,
            'stock'        => (int) $s->stock,
            'warn_stock'   => (int) $s->warn_stock,
            'weight'       => (string) $s->weight,
            'volume'       => (string) $s->volume,
            'status'       => (int) $s->status,
            'prices'       => $prices[$s->id] ?? [],
        ];

        if ($specType === 1) {
            $s = $skuRows->first();

            return ['sku' => $s ? $fmt($s) : null, 'prices' => $s ? ($prices[$s->id] ?? []) : [], 'specs' => [], 'skus' => []];
        }

        $specRows = DB::table('goods_spec')->where('goods_id', $goodsId)->orderBy('sort')->orderBy('id')->get();
        $valueRows = DB::table('goods_spec_value')->where('goods_id', $goodsId)->orderBy('sort')->orderBy('id')->get();
        $specLang = $this->trans->fetch('goods_spec_lang', 'spec_id', $specRows->pluck('id')->all(), ['name']);
        $valueLang = $this->trans->fetch('goods_spec_value_lang', 'value_id', $valueRows->pluck('id')->all(), ['name']);

        $specs = [];
        foreach ($specRows as $sp) {
            $values = [];
            foreach ($valueRows->where('spec_id', $sp->id) as $v) {
                $values[] = ['id' => (int) $v->id, 'name' => $valueLang[$v->id]['name'] ?? []];
            }
            $specs[] = ['id' => (int) $sp->id, 'name' => $specLang[$sp->id]['name'] ?? [], 'values' => $values];
        }

        $skus = [];
        foreach ($skuRows as $s) {
            $skus[] = $fmt($s) + [
                'value_ids' => array_map('intval', array_filter(explode(',', (string) $s->spec_value_ids), fn ($x) => $x !== '')),
            ];
        }

        return ['sku' => null, 'prices' => [], 'specs' => $specs, 'skus' => $skus];
    }

    // ------------------------------------------------------------------ 校验
    public function assertMulti(array $d): void
    {
        $specs = array_values((array) ($d['specs'] ?? []));
        $skus = array_values((array) ($d['skus'] ?? []));
        $default = $this->locale->defaultLang();

        if ($specs === [] || count($specs) > self::MAX_SPECS) {
            throw new BusinessException('多规格商品需要 1 到 ' . self::MAX_SPECS . ' 个规格项');
        }

        $keyToSpec = [];
        foreach ($specs as $i => $s) {
            $this->trans->assertDefaultFilled((array) ($s['name'] ?? []), '规格名称');
            $values = array_values((array) ($s['values'] ?? []));
            if ($values === []) {
                throw new BusinessException('每个规格项至少要有一个规格值');
            }
            $seen = [];
            foreach ($values as $v) {
                $key = (string) ($v['key'] ?? '');
                if ($key === '' || isset($keyToSpec[$key])) {
                    throw new BusinessException('规格值标识缺失或重复，请刷新页面后重试');
                }
                $names = (array) ($v['name'] ?? []);
                $this->trans->assertDefaultFilled($names, '规格值');
                $dn = mb_strtolower(trim((string) ($names[$default] ?? '')));
                if (isset($seen[$dn])) {
                    throw new BusinessException('同一规格项下规格值不能重复：' . $dn);
                }
                $seen[$dn] = true;
                $keyToSpec[$key] = $i;
            }
        }

        if ($skus === [] || count($skus) > self::MAX_SKUS) {
            throw new BusinessException('规格组合数量必须在 1 到 ' . self::MAX_SKUS . ' 之间，当前 ' . count($skus) . ' 个，请减少规格值');
        }

        $combos = [];
        $enabled = 0;
        foreach ($skus as $sku) {
            $keys = array_values((array) ($sku['value_keys'] ?? []));
            if (count($keys) !== count($specs)) {
                throw new BusinessException('规格组合不完整，请刷新页面后重试');
            }
            $idx = [];
            foreach ($keys as $k) {
                if (!isset($keyToSpec[(string) $k])) {
                    throw new BusinessException('规格组合包含不存在的规格值');
                }
                $idx[$keyToSpec[(string) $k]] = (string) $k;
            }
            if (count($idx) !== count($specs)) {
                throw new BusinessException('规格组合里每个规格项只能选一个值');
            }
            ksort($idx);
            $sig = implode('|', $idx);
            if (isset($combos[$sig])) {
                throw new BusinessException('存在重复的规格组合');
            }
            $combos[$sig] = true;
            if ((int) ($sku['status'] ?? 1) === 1) {
                $enabled++;
            }
        }
        if ($enabled === 0) {
            throw new BusinessException('至少启用一个规格组合');
        }
    }

    // ------------------------------------------------------------------ 保存：单规格
    public function saveSingle(Goods $goods, array $sku, array $prices, bool $create, Admin $op): void
    {
        $goodsId = (int) $goods->id;
        $row = GoodsSku::query()->where('goods_id', $goodsId)->orderBy('id')->first();
        $fields = $this->skuFields($goodsId, $sku, '', 1);

        if ($row) {
            $row->update($fields); // 库存不在这里改
        } else {
            $stock = (int) ($sku['stock'] ?? 0);
            $row = GoodsSku::query()->create($fields + ['stock' => $stock]);
            if ($stock > 0) {
                $this->logStock($goodsId, (int) $row->id, $stock, $stock, '初始库存', $op);
            }
        }

        $this->syncPrices((int) $row->id, $prices);
        $this->refreshTotals($goodsId);
    }

    // ------------------------------------------------------------------ 保存：多规格
    public function saveMulti(Goods $goods, array $d, bool $create, Admin $op): void
    {
        $goodsId = (int) $goods->id;
        $specs = array_values((array) $d['specs']);
        $skus = array_values((array) $d['skus']);

        $existSpecIds = DB::table('goods_spec')->where('goods_id', $goodsId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $existValueIds = DB::table('goods_spec_value')->where('goods_id', $goodsId)->pluck('id')->map(fn ($v) => (int) $v)->all();

        $keepSpecs = [];
        $keepValues = [];
        $keyToId = [];
        $keyToSpecIdx = [];

        foreach ($specs as $i => $s) {
            $sid = (int) ($s['id'] ?? 0);
            if ($sid > 0 && in_array($sid, $existSpecIds, true)) {
                DB::table('goods_spec')->where('id', $sid)->update(['sort' => $i]);
            } else {
                $sid = (int) DB::table('goods_spec')->insertGetId(['goods_id' => $goodsId, 'sort' => $i]);
            }
            $keepSpecs[] = $sid;
            $this->trans->sync('goods_spec_lang', 'spec_id', $sid, ['name' => (array) ($s['name'] ?? [])], ['name']);

            foreach (array_values((array) $s['values']) as $j => $v) {
                $vid = (int) ($v['id'] ?? 0);
                if ($vid > 0 && in_array($vid, $existValueIds, true)) {
                    DB::table('goods_spec_value')->where('id', $vid)->update(['spec_id' => $sid, 'sort' => $j]);
                } else {
                    $vid = (int) DB::table('goods_spec_value')->insertGetId(['spec_id' => $sid, 'goods_id' => $goodsId, 'image' => '', 'sort' => $j]);
                }
                $keepValues[] = $vid;
                $keyToId[(string) $v['key']] = $vid;
                $keyToSpecIdx[(string) $v['key']] = $i;
                $this->trans->sync('goods_spec_value_lang', 'value_id', $vid, ['name' => (array) ($v['name'] ?? [])], ['name']);
            }
        }

        // 删除被移除的规格值和规格项（含翻译）
        $dropValues = array_values(array_diff($existValueIds, $keepValues));
        if ($dropValues !== []) {
            DB::table('goods_spec_value_lang')->whereIn('value_id', $dropValues)->delete();
            DB::table('goods_spec_value')->whereIn('id', $dropValues)->delete();
        }
        $dropSpecs = array_values(array_diff($existSpecIds, $keepSpecs));
        if ($dropSpecs !== []) {
            DB::table('goods_spec_lang')->whereIn('spec_id', $dropSpecs)->delete();
            DB::table('goods_spec')->whereIn('id', $dropSpecs)->delete();
        }

        // SKU：优先按 id 匹配，其次按规格值组合匹配已有 SKU，否则新增
        $existing = GoodsSku::query()->where('goods_id', $goodsId)->get()->keyBy('id');
        $byCombo = [];
        foreach ($existing as $e) {
            if ((string) $e->spec_value_ids !== '') {
                $byCombo[(string) $e->spec_value_ids] = $e;
            }
        }

        $touched = [];
        foreach ($skus as $sku) {
            $tmp = [];
            foreach ((array) $sku['value_keys'] as $k) {
                $tmp[$keyToSpecIdx[(string) $k]] = $keyToId[(string) $k];
            }
            ksort($tmp);
            $combo = implode(',', $tmp);

            $row = null;
            $sid = (int) ($sku['id'] ?? 0);
            if ($sid > 0 && $existing->has($sid) && !isset($touched[$sid])) {
                $row = $existing->get($sid);
            } elseif (isset($byCombo[$combo]) && !isset($touched[(int) $byCombo[$combo]->id])) {
                $row = $byCombo[$combo];
            }

            $fields = $this->skuFields($goodsId, $sku, $combo, (int) ($sku['status'] ?? 1));
            if ($row) {
                $row->update($fields);
                $skuId = (int) $row->id;
            } else {
                $stock = (int) ($sku['stock'] ?? 0);
                $new = GoodsSku::query()->create($fields + ['stock' => $stock]);
                $skuId = (int) $new->id;
                if ($stock > 0) {
                    $this->logStock($goodsId, $skuId, $stock, $stock, $create ? '初始库存' : '新增规格初始库存', $op);
                }
            }
            $touched[$skuId] = true;
            $this->syncPrices($skuId, (array) ($sku['prices'] ?? []));
        }

        // 不再出现的 SKU：被订单引用的只停用（保留历史），否则删除
        foreach ($existing as $e) {
            if (isset($touched[(int) $e->id])) {
                continue;
            }
            if (DB::table('order_item')->where('sku_id', $e->id)->exists()) {
                $e->update(['status' => 0]);
            } else {
                DB::table('goods_price')->where('sku_id', $e->id)->delete();
                $e->delete();
            }
        }

        $this->refreshTotals($goodsId);
    }

    // ------------------------------------------------------------------ 汇总与流水
    /** 商品的展示价（启用 SKU 最低价）、划线价、总库存（启用 SKU 之和）统一在这里刷新 */
    public function refreshTotals(int $goodsId): void
    {
        $rows = GoodsSku::query()->where('goods_id', $goodsId)->where('status', 1)->get(['price', 'market_price', 'stock']);

        if ($rows->isEmpty()) {
            Goods::query()->where('id', $goodsId)->update(['stock' => 0]);

            return;
        }

        $market = $rows->pluck('market_price')->filter(fn ($v) => (float) $v > 0)->min();
        Goods::query()->where('id', $goodsId)->update([
            'price'        => $this->money($rows->min('price')),
            'market_price' => $this->money($market ?? 0),
            'stock'        => (int) $rows->sum('stock'),
        ]);
    }

    public function logStock(int $goodsId, int $skuId, int $change, int $after, string $remark, Admin $op): void
    {
        DB::table('stock_log')->insert([
            'goods_id'   => $goodsId,
            'sku_id'     => $skuId,
            'type'       => 4, // 后台调整
            'change_qty' => $change,
            'after_qty'  => $after,
            'ref_no'     => '',
            'operator'   => (string) $op->username,
            'remark'     => mb_substr($remark, 0, 250),
            'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ 内部
    private function skuFields(int $goodsId, array $sku, string $combo, int $status): array
    {
        return [
            'goods_id'       => $goodsId,
            'spec_value_ids' => $combo,
            'sku_sn'         => trim((string) ($sku['sku_sn'] ?? '')),
            'image'          => trim((string) ($sku['image'] ?? '')),
            'price'          => $this->money($sku['price'] ?? 0),
            'cost_price'     => $this->money($sku['cost_price'] ?? 0),
            'market_price'   => $this->money($sku['market_price'] ?? 0),
            'warn_stock'     => (int) ($sku['warn_stock'] ?? 0),
            'weight'         => number_format((float) ($sku['weight'] ?? 0), 3, '.', ''),
            'volume'         => number_format((float) ($sku['volume'] ?? 0), 3, '.', ''),
            'status'         => $status,
        ];
    }

    /** 只维护当前启用的非基准币种的单独定价；空值表示按汇率折算 */
    private function syncPrices(int $skuId, array $prices): void
    {
        $valid = [];
        foreach ($this->locale->currencies() as $c) {
            if ($c['is_base'] !== 1) {
                $valid[] = $c['code'];
            }
        }
        if ($valid === []) {
            return;
        }

        DB::table('goods_price')->where('sku_id', $skuId)->whereIn('currency', $valid)->delete();
        foreach ($prices as $code => $p) {
            if (in_array($code, $valid, true) && $p !== null && $p !== '' && (float) $p > 0) {
                DB::table('goods_price')->insert(['sku_id' => $skuId, 'currency' => $code, 'price' => $this->money($p)]);
            }
        }
    }

    private function money(mixed $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }
}
