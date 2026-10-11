<?php

namespace App\Services\Open;

use App\Models\GoodsSku;
use App\Models\OpenApp;
use App\Services\Admin\GoodsSkuService;
use App\Services\Api\CatalogService;
use App\Services\Common\LocaleService;
use App\Services\Common\OpenAppService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/** 开放接口：规格（SKU）的读取和库存修改。库存变动和后台手工调整一样写库存日志 */
class OpenGoodsService
{
    public function __construct(
        private CatalogService $catalog,
        private LocaleService $locale,
        private GoodsSkuService $skus,
        private OpenAppService $apps,
    ) {}

    /**
     * 规格列表（不含已删除的商品）。价格是基准币种的标准价，名称是默认语言的。
     * @param array{sku_sn?:string,goods_id?:int|string,page?:int|string,page_size?:int|string} $q sku_sn 可以用逗号给多个
     */
    public function list(array $q): array
    {
        $size = min(100, max(1, (int) ($q['page_size'] ?? 50)));
        $sns = array_values(array_filter(array_map('trim', explode(',', (string) ($q['sku_sn'] ?? '')))));
        $p = DB::table('goods_sku as s')->join('goods as g', 'g.id', '=', 's.goods_id')->whereNull('g.deleted_at')
            ->when($sns !== [], fn ($x) => $x->whereIn('s.sku_sn', $sns))
            ->when(($q['goods_id'] ?? '') !== '', fn ($x) => $x->where('s.goods_id', (int) $q['goods_id']))
            ->orderBy('s.id')
            ->select('s.id', 's.goods_id', 's.sku_sn', 's.price', 's.market_price', 's.stock', 's.weight', 's.volume', 's.status', 's.updated_at', 'g.is_on_sale')
            ->paginate($size, ['*'], 'page', max(1, (int) ($q['page'] ?? 1)));

        $rows = collect($p->items());
        $briefs = $this->catalog->skuBriefs($rows->pluck('id')->map(fn ($v) => (int) $v)->all(), $this->locale->defaultLang(), $this->locale->baseCurrency());
        $list = $rows->map(fn ($r) => [
            'sku_id' => (int) $r->id, 'goods_id' => (int) $r->goods_id, 'sku_sn' => (string) $r->sku_sn,
            'title' => (string) ($briefs[(int) $r->id]['title'] ?? ''), 'spec_text' => (string) ($briefs[(int) $r->id]['spec_text'] ?? ''),
            'price' => (string) $r->price, 'market_price' => $r->market_price === null ? null : (string) $r->market_price,
            'stock' => (int) $r->stock, 'weight' => (string) $r->weight, 'volume' => (string) $r->volume,
            // enabled：这个规格是否启用；on_sale：所属商品是否上架。两个都是 true 买家才买得到
            'enabled' => (int) $r->status === 1, 'on_sale' => (int) $r->is_on_sale === 1,
            'updated_at' => OpenTime::format($r->updated_at),
        ])->all();

        return ['list' => $list, 'total' => $p->total(), 'page' => $p->currentPage(), 'page_size' => $p->perPage(), 'currency' => $this->locale->baseCurrency()];
    }

    /**
     * 批量改库存。一条一条处理、互不影响：每条各有结果，一条失败不会让别的也失败。
     * @param array<int,array{sku_id?:int|string,sku_sn?:string,mode?:string,qty:int|string,remark?:string}> $items
     * @return array<int,array{sku_id:int,sku_sn:string,ok:bool,stock:?int,msg:string}>
     */
    public function updateStock(array $items, OpenApp $app): array
    {
        $out = [];
        foreach ($items as $item) {
            try {
                $out[] = $this->updateOne($item, $app);
            } catch (BusinessException $e) {
                $out[] = ['sku_id' => (int) ($item['sku_id'] ?? 0), 'sku_sn' => (string) ($item['sku_sn'] ?? ''), 'ok' => false, 'stock' => null, 'msg' => $e->getMessage()];
            }
        }

        return $out;
    }

    private function updateOne(array $item, OpenApp $app): array
    {
        return DB::transaction(function () use ($item, $app) {
            $sn = trim((string) ($item['sku_sn'] ?? ''));
            $found = GoodsSku::query()
                ->whereIn('goods_id', fn ($q) => $q->select('id')->from('goods')->whereNull('deleted_at'))
                ->when(!empty($item['sku_id']), fn ($q) => $q->whereKey((int) $item['sku_id']), fn ($q) => $q->where('sku_sn', $sn))
                ->lockForUpdate()->get();
            if ($found->isEmpty()) {
                throw new BusinessException(!empty($item['sku_id']) ? '规格不存在' : "货号 {$sn} 不存在");
            }
            // 货号在后台不要求唯一。对应多个规格时不猜、不同时改，让管理员先把货号改成唯一的
            if ($found->count() > 1) {
                throw new BusinessException("货号 {$sn} 对应 {$found->count()} 个规格，请先在后台把货号改成唯一的，或改用 sku_id");
            }
            $sku = $found->first();
            $qty = (int) $item['qty'];
            $new = ($item['mode'] ?? 'set') === 'add' ? (int) $sku->stock + $qty : $qty;
            if ($new < 0) {
                throw new BusinessException('库存不能小于 0，当前库存 ' . (int) $sku->stock);
            }
            $change = $new - (int) $sku->stock;
            if ($change !== 0) {
                $sku->update(['stock' => $new]);
                $this->skus->refreshTotals((int) $sku->goods_id);
                $remark = trim((string) ($item['remark'] ?? ''));
                $this->skus->logStock((int) $sku->goods_id, (int) $sku->id, $change, $new, $remark !== '' ? $remark : '开放接口调整', $this->apps->operator($app));
            }

            return ['sku_id' => (int) $sku->id, 'sku_sn' => (string) $sku->sku_sn, 'ok' => true, 'stock' => $new, 'msg' => ''];
        });
    }
}
