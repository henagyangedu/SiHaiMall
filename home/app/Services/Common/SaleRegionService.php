<?php

namespace App\Services\Common;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 销售区域匹配（带缓存）：按收货地址找出适用的销售区域，供区域定价使用。
 * 后台修改销售区域后必须调用 flush()。
 *
 * 匹配规则：中国大陆地址按「省 + 市」优先，其次「整个省」；其他国家/地区按国家码。
 * 没有匹配到任何启用的区域时返回 null，表示按标准价销售。
 */
class SaleRegionService
{
    public const CN = 'CN';

    private const KEY = 'mall:sale_regions';

    /**
     * @param array<string,mixed> $address 至少含 country_code，国内地址还需 province、city
     * @return array{id:int,name:string,currency:string}|null
     */
    public function match(array $address): ?array
    {
        $data = $this->data();
        $id = self::pick(
            $data['areas'],
            (string) ($address['country_code'] ?? ''),
            (string) ($address['province'] ?? ''),
            (string) ($address['city'] ?? ''),
        );

        return $id !== null ? ($data['regions'][$id] ?? null) : null;
    }

    /** 会员默认收货地址所在的销售区域（商品浏览、购物车展示价用） */
    public function forMember(int $memberId): ?array
    {
        if ($this->data()['regions'] === []) {
            return null;
        }
        $addr = DB::table('member_address')->where('member_id', $memberId)
            ->orderByDesc('is_default')->orderByDesc('id')->first(['country_code', 'province', 'city']);

        return $addr ? $this->match((array) $addr) : null;
    }

    /**
     * 纯匹配逻辑：返回命中的区域 ID。
     * @param array<int,array{region_id:int,country_code:string,province:string,city:string}> $areas
     */
    public static function pick(array $areas, string $country, string $province, string $city): ?int
    {
        $country = strtoupper(trim($country));
        $province = trim($province);
        $city = trim($city);
        if ($country === '') {
            return null;
        }

        $wholeProvince = null;
        foreach ($areas as $a) {
            if ($a['country_code'] !== $country) {
                continue;
            }
            if ($country !== self::CN) {
                return (int) $a['region_id'];
            }
            if ($a['province'] !== $province) {
                continue;
            }
            if ($a['city'] === '') {
                $wholeProvince = (int) $a['region_id'];
            } elseif ($a['city'] === $city) {
                return (int) $a['region_id'];
            }
        }

        return $wholeProvince;
    }

    /** 中国大陆省 → 市 标准名单 @return array<string,string[]> */
    public function cnDivisions(): array
    {
        return (array) config('cn_divisions');
    }

    public function flush(): void
    {
        Cache::forget(self::KEY);
    }

    /** @return array{regions:array<int,array{id:int,name:string,currency:string}>,areas:array<int,array<string,mixed>>} */
    private function data(): array
    {
        $v = Cache::get(self::KEY);
        if ($v === null) {
            $regions = DB::table('sale_region')->where('status', 1)->whereNull('deleted_at')->get(['id', 'name', 'currency'])
                ->mapWithKeys(fn ($r) => [(int) $r->id => ['id' => (int) $r->id, 'name' => $r->name, 'currency' => $r->currency]])->all();
            $areas = $regions === [] ? [] : DB::table('sale_region_area')->whereIn('region_id', array_keys($regions))
                ->get(['region_id', 'country_code', 'province', 'city'])
                ->map(fn ($a) => ['region_id' => (int) $a->region_id, 'country_code' => $a->country_code, 'province' => $a->province, 'city' => $a->city])->all();
            $v = ['regions' => $regions, 'areas' => $areas];
            Cache::put(self::KEY, $v, 3600);
        }

        return $v;
    }
}
