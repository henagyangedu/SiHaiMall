<?php

namespace App\Services\Admin;

use App\Models\TaxRate;
use App\Services\Api\OrderService as ApiOrderService;
use App\Support\Exceptions\BusinessException;

/** 税率规则的后台管理。怎么匹配、怎么算在 Common\TaxService */
class TaxRateService
{
    public function list(): array
    {
        return TaxRate::query()->orderBy('country_code')->orderBy('province')->get()->map(fn (TaxRate $r) => [
            'id' => (int) $r->id, 'country_code' => $r->country_code, 'province' => (string) $r->province, 'name' => $r->name,
            'rate' => ApiOrderService::rateText((string) $r->rate), 'included' => (int) $r->included,
            'shipping_taxable' => (int) $r->shipping_taxable, 'status' => (int) $r->status,
        ])->all();
    }

    /** 新增（$id = 0）或修改。一个地区只能有一条规则 */
    public function save(int $id, array $d): void
    {
        $row = $id > 0 ? (TaxRate::query()->find($id) ?? throw new BusinessException('税率规则不存在')) : new TaxRate();
        $country = strtoupper(trim((string) $d['country_code']));
        $province = trim((string) ($d['province'] ?? ''));
        // 国内的省份要和收货地址用的标准名称一致，否则永远匹配不上
        if ($country === 'CN' && $province !== '' && !array_key_exists($province, (array) config('cn_divisions'))) {
            throw new BusinessException('省份名称不在标准名单里：' . $province);
        }
        // 省 / 州匹配时不分大小写，查重也一样
        $dup = TaxRate::query()->where('country_code', $country)->where('id', '!=', $id)->get()
            ->contains(fn (TaxRate $r) => mb_strtolower((string) $r->province) === mb_strtolower($province));
        if ($dup) {
            throw new BusinessException('这个地区已经有一条税率规则了');
        }

        $row->fill([
            'country_code' => $country, 'province' => $province, 'name' => trim((string) $d['name']), 'rate' => (string) $d['rate'],
            'included' => (int) ($d['included'] ?? 0), 'shipping_taxable' => (int) ($d['shipping_taxable'] ?? 0), 'status' => (int) ($d['status'] ?? 1),
        ])->save();
    }

    public function delete(int $id): void
    {
        TaxRate::query()->whereKey($id)->delete();
    }
}
