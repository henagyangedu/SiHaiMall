<?php

namespace App\Services\Admin;

use App\Services\Common\LocaleService;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * 商品的两块附加内容（后台保存和读取；买家端的展示在 Api\CatalogService）：
 * - 规格参数：每个商品自己填“名称 / 值”，多语言，最多 30 行。名称没有统一的字典，所以做不了跨商品的参数对比和筛选。
 * - 配套商品：这个商品的耗材、配件，最多 12 个。只存“设备 → 配件”一个方向，配件页上的“适用设备”是反过来查的。
 * 保存都是整体覆盖：提交什么就是什么；请求里没带这个字段就不动原来的。必须在事务里调用。
 */
class GoodsExtraService
{
    public const MAX_PARAMS = 30;

    public const MAX_RELATED = 12;

    public function __construct(private TranslationService $trans, private LocaleService $locale) {}

    /** @param array{params?:array,related_ids?:array} $d 商品表单提交的内容 */
    public function save(int $goodsId, array $d): void
    {
        if (array_key_exists('params', $d)) {
            $this->saveParams($goodsId, (array) ($d['params'] ?? []));
        }
        if (array_key_exists('related_ids', $d)) {
            $this->saveRelated($goodsId, (array) ($d['related_ids'] ?? []));
        }
    }

    /** @return array{params:array<int,array{name:array,value:array}>,related_ids:int[],related:array<int,array{id:int,title:string}>} */
    public function load(int $goodsId): array
    {
        $ids = DB::table('goods_param')->where('goods_id', $goodsId)->orderBy('sort')->orderBy('id')->pluck('id')->all();
        $texts = $this->trans->fetch('goods_param_lang', 'param_id', $ids, ['name', 'value']);

        $relatedIds = DB::table('goods_relation')->where('goods_id', $goodsId)->orderBy('sort')->orderBy('id')->pluck('related_goods_id')->map(fn ($v) => (int) $v)->all();
        $titles = $this->trans->fetch('goods_lang', 'goods_id', $relatedIds, ['title']);

        return [
            'params' => array_map(fn ($id) => ['name' => $texts[$id]['name'] ?? [], 'value' => $texts[$id]['value'] ?? []], $ids),
            'related_ids' => $relatedIds,
            // 给表单的选择框显示名称用
            'related' => array_map(fn ($id) => ['id' => $id, 'title' => $this->trans->resolve($titles[$id]['title'] ?? []) ?: "商品 #{$id}"], $relatedIds),
        ];
    }

    /** @param array<int,array{name?:array,value?:array}> $rows */
    public function saveParams(int $goodsId, array $rows): void
    {
        // 名称和值都没填的空行直接丢掉
        $rows = array_values(array_filter($rows, fn ($r) => $this->filled((array) ($r['name'] ?? [])) || $this->filled((array) ($r['value'] ?? []))));
        if (count($rows) > self::MAX_PARAMS) {
            throw new BusinessException('规格参数最多 ' . self::MAX_PARAMS . ' 行');
        }
        foreach ($rows as $i => $r) {
            $this->trans->assertDefaultFilled((array) ($r['name'] ?? []), '第 ' . ($i + 1) . ' 行参数的名称');
            $this->trans->assertDefaultFilled((array) ($r['value'] ?? []), '第 ' . ($i + 1) . ' 行参数的值');
        }

        $old = DB::table('goods_param')->where('goods_id', $goodsId)->pluck('id');
        DB::table('goods_param_lang')->whereIn('param_id', $old)->delete();
        DB::table('goods_param')->where('goods_id', $goodsId)->delete();
        foreach ($rows as $i => $r) {
            $id = (int) DB::table('goods_param')->insertGetId(['goods_id' => $goodsId, 'sort' => $i]);
            $this->trans->sync('goods_param_lang', 'param_id', $id, ['name' => (array) $r['name'], 'value' => (array) $r['value']], ['name', 'value']);
        }
    }

    /** @param int[] $ids 配套商品的 ID，顺序就是展示顺序 */
    public function saveRelated(int $goodsId, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (in_array($goodsId, $ids, true)) {
            throw new BusinessException('不能把商品自己设为配套商品');
        }
        if (count($ids) > self::MAX_RELATED) {
            throw new BusinessException('配套商品最多 ' . self::MAX_RELATED . ' 个');
        }
        if ($ids !== [] && DB::table('goods')->whereIn('id', $ids)->whereNull('deleted_at')->count() !== count($ids)) {
            throw new BusinessException('选择的配套商品里有不存在的');
        }

        DB::table('goods_relation')->where('goods_id', $goodsId)->delete();
        foreach ($ids as $i => $id) {
            DB::table('goods_relation')->insert(['goods_id' => $goodsId, 'related_goods_id' => $id, 'sort' => $i, 'created_at' => now()]);
        }
    }

    private function filled(array $texts): bool
    {
        foreach ($texts as $t) {
            if (trim((string) $t) !== '') {
                return true;
            }
        }

        return false;
    }
}
