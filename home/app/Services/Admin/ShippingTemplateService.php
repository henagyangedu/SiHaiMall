<?php

namespace App\Services\Admin;

use App\Models\ShippingTemplate;
use App\Models\ShippingTemplateRegion;
use App\Services\Common\ShippingFeeService;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

/**
 * 运费模板管理。计费规则见 ShippingFeeService。
 * 地区规则的 countries 存逗号分隔的国家码（* 表示其他所有国家/地区），provinces 存逗号分隔的省/州名称；
 * 金额字段均为基准币种。
 */
class ShippingTemplateService
{
    private const MAX_REGIONS = 100;

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));

        $paginator = ShippingTemplate::query()
            ->when($kw !== '', fn ($q) => $q->where('name', 'like', '%' . addcslashes($kw, '%_\\') . '%'))
            ->orderByDesc('id')
            ->paginate($size);

        $ids = collect($paginator->items())->pluck('id')->all();
        $regionCount = DB::table('shipping_template_region')->whereIn('template_id', $ids)
            ->selectRaw('template_id, count(*) as c')->groupBy('template_id')->pluck('c', 'template_id');
        $goodsCount = DB::table('goods')->whereIn('shipping_template_id', $ids)->whereNull('deleted_at')
            ->selectRaw('shipping_template_id, count(*) as c')->groupBy('shipping_template_id')->pluck('c', 'shipping_template_id');

        $paginator->through(fn (ShippingTemplate $t) => [
            'id'              => (int) $t->id,
            'name'            => $t->name,
            'charge_type'     => (int) $t->charge_type,
            'is_free_default' => (int) $t->is_free_default,
            'region_count'    => (int) ($regionCount[$t->id] ?? 0),
            'goods_count'     => (int) ($goodsCount[$t->id] ?? 0),
            'updated_at'      => $t->updated_at?->format('Y-m-d H:i:s'),
        ]);

        return Paginate::format($paginator);
    }

    /** 运费模板下拉（商品表单用） */
    public function options(): array
    {
        return ShippingTemplate::query()->orderByDesc('id')->get(['id', 'name', 'charge_type', 'is_free_default'])
            ->map(fn (ShippingTemplate $t) => [
                'id' => (int) $t->id, 'name' => $t->name,
                'charge_type' => (int) $t->charge_type, 'is_free_default' => (int) $t->is_free_default,
            ])->all();
    }

    public function detail(int $id): array
    {
        $t = $this->find($id);
        $regions = ShippingTemplateRegion::query()->where('template_id', $id)->orderBy('id')->get();

        return [
            'id'              => (int) $t->id,
            'name'            => $t->name,
            'charge_type'     => (int) $t->charge_type,
            'is_free_default' => (int) $t->is_free_default,
            'regions'         => $regions->map(fn (ShippingTemplateRegion $r) => [
                'countries'   => ShippingFeeService::split((string) $r->countries, true),
                'provinces'   => ShippingFeeService::split((string) $r->provinces, false),
                'first_unit'  => (string) $r->first_unit,
                'first_fee'   => (string) $r->first_fee,
                'step_unit'   => (string) $r->step_unit,
                'step_fee'    => (string) $r->step_fee,
                'free_amount' => (string) $r->free_amount,
            ])->all(),
        ];
    }

    public function create(array $d): ShippingTemplate
    {
        $regions = $this->cleanRegions($d);

        return DB::transaction(function () use ($d, $regions) {
            $t = ShippingTemplate::query()->create($this->fields($d));
            $this->saveRegions((int) $t->id, $regions);

            return $t;
        });
    }

    public function update(int $id, array $d): ShippingTemplate
    {
        $t = $this->find($id);
        $regions = $this->cleanRegions($d);

        return DB::transaction(function () use ($t, $id, $d, $regions) {
            $t->update($this->fields($d));
            ShippingTemplateRegion::query()->where('template_id', $id)->delete();
            $this->saveRegions($id, $regions);

            return $t;
        });
    }

    public function delete(int $id): void
    {
        $t = $this->find($id);
        $used = DB::table('goods')->where('shipping_template_id', $id)->whereNull('deleted_at')->count();
        if ($used > 0) {
            throw new BusinessException("还有 {$used} 个商品在使用该运费模板，不能删除");
        }

        DB::transaction(function () use ($t, $id) {
            ShippingTemplateRegion::query()->where('template_id', $id)->delete();
            $t->delete();
        });
    }

    private function find(int $id): ShippingTemplate
    {
        return ShippingTemplate::query()->find($id) ?? throw new BusinessException('运费模板不存在');
    }

    private function fields(array $d): array
    {
        return [
            'name'            => trim((string) $d['name']),
            'charge_type'     => (int) $d['charge_type'],
            'is_free_default' => (int) ($d['is_free_default'] ?? 0),
        ];
    }

    /**
     * 规整并校验地区规则：同一国家（或同一国家的同一省份）不能出现在两条规则里。
     * @return array<int,array<string,string>>
     */
    private function cleanRegions(array $d): array
    {
        $free = (int) ($d['is_free_default'] ?? 0) === 1;
        $rows = array_values((array) ($d['regions'] ?? []));
        if ($rows === [] && !$free) {
            throw new BusinessException('请至少添加一条配送地区规则，或开启“默认包邮”');
        }
        if (count($rows) > self::MAX_REGIONS) {
            throw new BusinessException('配送地区规则最多 ' . self::MAX_REGIONS . ' 条');
        }

        $out = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $no = $i + 1;
            // 表单校验的 numeric 允许科学计数法，bcmath 不接受，先规整成定点小数
            $firstUnit = $this->dec($r['first_unit'] ?? 0, 3);
            $stepUnit = $this->dec($r['step_unit'] ?? 0, 3);
            $stepFee = $this->dec($r['step_fee'] ?? 0, 2);
            $countries = ShippingFeeService::split(implode(',', (array) ($r['countries'] ?? [])), true);
            $provinces = ShippingFeeService::split(implode(',', array_map(
                fn ($p) => str_replace(',', ' ', (string) $p),
                (array) ($r['provinces'] ?? [])
            )), false);

            if ($countries === []) {
                throw new BusinessException("第 {$no} 条规则没有选择国家/地区");
            }
            if (in_array('*', $countries, true) && count($countries) > 1) {
                throw new BusinessException("第 {$no} 条规则：“其他所有国家/地区”不能和具体国家一起选");
            }
            if ($provinces !== [] && ($countries === ['*'] || count($countries) !== 1)) {
                throw new BusinessException("第 {$no} 条规则：只有选择单个国家时才能细分省/州");
            }
            if (bccomp($firstUnit, '0', 3) <= 0) {
                throw new BusinessException("第 {$no} 条规则：首件/首重/首体积必须大于 0");
            }
            if (bccomp($stepFee, '0', 2) > 0 && bccomp($stepUnit, '0', 3) <= 0) {
                throw new BusinessException("第 {$no} 条规则：设置了续费时，续件/续重/续体积必须大于 0");
            }

            foreach ($countries as $c) {
                foreach ($provinces ?: [''] as $p) {
                    $key = $c . '|' . mb_strtolower($p);
                    if (isset($seen[$key])) {
                        throw new BusinessException("第 {$no} 条规则与第 {$seen[$key]} 条规则的地区重复：" . ($c === '*' ? '其他所有国家/地区' : $c) . ($p !== '' ? " {$p}" : ''));
                    }
                    $seen[$key] = $no;
                }
            }

            $out[] = [
                'countries'   => implode(',', $countries),
                'provinces'   => implode(',', $provinces),
                'first_unit'  => $firstUnit,
                'first_fee'   => $this->dec($r['first_fee'] ?? 0, 2),
                'step_unit'   => $stepUnit,
                'step_fee'    => $stepFee,
                'free_amount' => $this->dec($r['free_amount'] ?? 0, 2),
            ];
        }

        return $out;
    }

    private function dec(mixed $v, int $scale): string
    {
        return number_format((float) $v, $scale, '.', '');
    }

    private function saveRegions(int $templateId, array $regions): void
    {
        foreach ($regions as $r) {
            ShippingTemplateRegion::query()->create(['template_id' => $templateId] + $r);
        }
    }
}
