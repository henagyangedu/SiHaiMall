<?php

namespace App\Services\Admin;

use App\Models\SaleRegion;
use App\Models\SaleRegionArea;
use App\Services\Common\LocaleService;
use App\Services\Common\SaleRegionService as RegionMatcher;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

/**
 * 销售区域管理（区域定价的基础数据）。匹配与取价规则见 Common\SaleRegionService、PriceService。
 *
 * 载荷约定：
 *   国内区域 scope=1：areas = [{province, cities:[]}]，cities 为空表示整个省
 *   境外区域 scope=2：countries = [国家码, ...]（不含 CN，中国大陆只能配成国内区域）
 * 同一地区只能属于一个区域；「整个省」和「该省下的某个市」可以分属两个区域，匹配时市优先。
 */
class SaleRegionService
{
    public function __construct(private LocaleService $locale, private RegionMatcher $matcher) {}

    /** 全部区域（数量有限，不分页） */
    public function list(array $f): array
    {
        $kw = trim((string) ($f['keyword'] ?? ''));
        $rows = SaleRegion::query()
            ->when($kw !== '', fn ($q) => $q->where('name', 'like', '%' . addcslashes($kw, '%_\\') . '%'))
            ->when(!empty($f['scope']), fn ($q) => $q->where('scope', (int) $f['scope']))
            ->orderBy('scope')->orderBy('sort')->orderBy('id')->get();

        $ids = $rows->pluck('id')->all();
        $areas = SaleRegionArea::query()->whereIn('region_id', $ids)->orderBy('id')->get()->groupBy('region_id');
        $priced = DB::table('goods_region_price')->whereIn('region_id', $ids)
            ->selectRaw('region_id, count(*) as c')->groupBy('region_id')->pluck('c', 'region_id');

        return $rows->map(fn (SaleRegion $r) => $this->format($r, $areas->get($r->id) ?? collect()) + [
            'sku_count'  => (int) ($priced[$r->id] ?? 0),
            'updated_at' => $r->updated_at?->format('Y-m-d H:i:s'),
        ])->all();
    }

    /** 启用的区域（商品定价表单用） */
    public function options(): array
    {
        return SaleRegion::query()->where('status', 1)->orderBy('scope')->orderBy('sort')->orderBy('id')->get()
            ->map(fn (SaleRegion $r) => ['id' => (int) $r->id, 'name' => $r->name, 'scope' => (int) $r->scope, 'currency' => $r->currency])->all();
    }

    public function create(array $d): SaleRegion
    {
        $areas = $this->cleanAreas($d, null);

        $region = DB::transaction(function () use ($d, $areas) {
            $region = SaleRegion::query()->create($this->fields($d));
            $this->saveAreas((int) $region->id, $areas);

            return $region;
        });
        $this->matcher->flush();

        return $region;
    }

    public function update(int $id, array $d): SaleRegion
    {
        $region = $this->find($id);
        if ((int) $d['scope'] !== (int) $region->scope) {
            throw new BusinessException('区域类型（国内/境外）创建后不能更改');
        }
        $areas = $this->cleanAreas($d, $id);
        $hasPrices = DB::table('goods_region_price')->where('region_id', $id)->exists();
        if ($hasPrices && (string) $d['currency'] !== (string) $region->currency) {
            throw new BusinessException('已有商品设置了该区域的区域价，不能更改定价币种');
        }

        DB::transaction(function () use ($region, $id, $d, $areas) {
            $region->update($this->fields($d));
            SaleRegionArea::query()->where('region_id', $id)->delete();
            $this->saveAreas($id, $areas);
        });
        $this->matcher->flush();

        return $region;
    }

    public function setStatus(int $id, int $status): void
    {
        $this->find($id)->update(['status' => $status]);
        $this->matcher->flush();
    }

    /** 删除区域，同时清掉它覆盖的地区和所有商品的该区域价 */
    public function delete(int $id): void
    {
        $region = $this->find($id);

        DB::transaction(function () use ($region, $id) {
            SaleRegionArea::query()->where('region_id', $id)->delete();
            DB::table('goods_region_price')->where('region_id', $id)->delete();
            $region->delete();
        });
        $this->matcher->flush();
    }

    private function find(int $id): SaleRegion
    {
        return SaleRegion::query()->find($id) ?? throw new BusinessException('销售区域不存在');
    }

    private function format(SaleRegion $r, $areas): array
    {
        $out = [
            'id' => (int) $r->id, 'name' => $r->name, 'scope' => (int) $r->scope, 'currency' => $r->currency,
            'sort' => (int) $r->sort, 'status' => (int) $r->status, 'areas' => [], 'countries' => [],
        ];
        if ((int) $r->scope === SaleRegion::SCOPE_OVERSEAS) {
            $out['countries'] = $areas->pluck('country_code')->values()->all();

            return $out;
        }
        foreach ($areas->groupBy('province') as $province => $rows) {
            $out['areas'][] = ['province' => (string) $province, 'cities' => $rows->pluck('city')->filter(fn ($c) => $c !== '')->values()->all()];
        }

        return $out;
    }

    private function fields(array $d): array
    {
        $currency = strtoupper(trim((string) $d['currency']));
        if (!in_array($currency, $this->locale->currencyCodes(), true)) {
            throw new BusinessException('定价币种未启用：' . $currency);
        }

        return [
            'name'     => trim((string) $d['name']),
            'scope'    => (int) $d['scope'],
            'currency' => $currency,
            'sort'     => (int) ($d['sort'] ?? 0),
            'status'   => (int) ($d['status'] ?? 1),
        ];
    }

    /**
     * 规整并校验覆盖地区，返回待写入的 [country_code, province, city] 行。
     * @return array<int,array{country_code:string,province:string,city:string}>
     */
    private function cleanAreas(array $d, ?int $selfId): array
    {
        $rows = [];
        if ((int) $d['scope'] === SaleRegion::SCOPE_OVERSEAS) {
            foreach ((array) ($d['countries'] ?? []) as $c) {
                $c = strtoupper(trim((string) $c));
                if ($c === RegionMatcher::CN) {
                    throw new BusinessException('中国大陆请配置为“国内区域”，按省市划分');
                }
                $rows[$c] = ['country_code' => $c, 'province' => '', 'city' => ''];
            }
            if ($rows === []) {
                throw new BusinessException('请至少选择一个国家/地区');
            }
        } else {
            $divisions = $this->matcher->cnDivisions();
            foreach ((array) ($d['areas'] ?? []) as $a) {
                $province = trim((string) ($a['province'] ?? ''));
                if (!isset($divisions[$province])) {
                    throw new BusinessException('省份不在标准名单内：' . $province);
                }
                $cities = array_values(array_unique(array_map(fn ($c) => trim((string) $c), (array) ($a['cities'] ?? []))));
                foreach ($cities ?: [''] as $city) {
                    if ($city !== '' && !in_array($city, $divisions[$province], true)) {
                        throw new BusinessException("城市不在标准名单内：{$province} {$city}");
                    }
                    $rows[$province . '|' . $city] = ['country_code' => RegionMatcher::CN, 'province' => $province, 'city' => $city];
                }
            }
            if ($rows === []) {
                throw new BusinessException('请至少选择一个省份');
            }
        }

        // 与其他区域冲突的地区（已删除区域的地区行在删除时已清掉）
        $taken = DB::table('sale_region_area as a')->join('sale_region as r', 'r.id', '=', 'a.region_id')
            ->when($selfId, fn ($q) => $q->where('a.region_id', '!=', $selfId))
            ->whereIn('a.country_code', array_unique(array_column($rows, 'country_code')))
            ->get(['a.country_code', 'a.province', 'a.city', 'r.name']);
        foreach ($taken as $t) {
            $key = $t->country_code === RegionMatcher::CN ? $t->province . '|' . $t->city : $t->country_code;
            if (isset($rows[$key])) {
                $label = $t->country_code === RegionMatcher::CN ? trim($t->province . ' ' . $t->city) : $t->country_code;
                throw new BusinessException("{$label} 已属于销售区域「{$t->name}」，一个地区只能属于一个区域");
            }
        }

        return array_values($rows);
    }

    private function saveAreas(int $regionId, array $areas): void
    {
        foreach ($areas as $a) {
            SaleRegionArea::query()->create(['region_id' => $regionId] + $a);
        }
    }
}
