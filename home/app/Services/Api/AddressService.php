<?php

namespace App\Services\Api;

use App\Models\MemberAddress;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

class AddressService
{
    private const MAX = 20;
    private const FIELDS = ['name', 'phone_area', 'phone', 'country_code', 'province', 'city', 'district', 'address', 'address2', 'postcode'];

    public function list(int $memberId): array
    {
        return MemberAddress::query()->where('member_id', $memberId)->orderByDesc('is_default')->orderByDesc('id')->get()->all();
    }

    public function find(int $memberId, int $id): MemberAddress
    {
        return MemberAddress::query()->where('member_id', $memberId)->find($id) ?? throw new BusinessException('收货地址不存在');
    }

    /** 新增 / 修改（$id 为 0 表示新增）。第一条地址自动设为默认 */
    public function save(int $memberId, int $id, array $d): MemberAddress
    {
        return DB::transaction(function () use ($memberId, $id, $d) {
            // 锁会员的地址行，避免并发设置默认
            $all = MemberAddress::query()->where('member_id', $memberId)->lockForUpdate()->get();
            // 空值（null）统一成空串，库里这些列都是 NOT NULL
            $data = array_map(fn ($v) => (string) ($v ?? ''), array_intersect_key($d, array_flip(self::FIELDS)));
            $data['phone_area'] = ($data['phone_area'] ?? '') !== '' ? $data['phone_area'] : '86';
            $data['country_code'] = strtoupper((string) ($data['country_code'] ?? 'CN'));
            $this->assertCnDivision($data);

            if ($id > 0) {
                $addr = $all->firstWhere('id', $id) ?? throw new BusinessException('收货地址不存在');
                $addr->fill($data)->save();
            } else {
                if ($all->count() >= self::MAX) {
                    throw new BusinessException('最多保存 ' . self::MAX . ' 个收货地址');
                }
                $addr = MemberAddress::query()->create($data + ['member_id' => $memberId, 'is_default' => $all->isEmpty() ? 1 : 0]);
            }

            if (!empty($d['is_default'])) {
                $this->makeDefault($memberId, (int) $addr->id);
                $addr->refresh();
            }

            return $addr;
        });
    }

    public function setDefault(int $memberId, int $id): void
    {
        $this->find($memberId, $id);
        DB::transaction(fn () => $this->makeDefault($memberId, $id));
    }

    public function delete(int $memberId, int $id): void
    {
        DB::transaction(function () use ($memberId, $id) {
            $addr = MemberAddress::query()->where('member_id', $memberId)->lockForUpdate()->find($id) ?? throw new BusinessException('收货地址不存在');
            $wasDefault = (int) $addr->is_default === 1;
            $addr->delete();

            // 删除了默认地址：把最近的一条补为默认
            if ($wasDefault) {
                $next = MemberAddress::query()->where('member_id', $memberId)->orderByDesc('id')->first();
                $next?->forceFill(['is_default' => 1])->save();
            }
        });
    }

    /** 中国大陆地址的省、市必须取自标准名单（config/cn_divisions.php），否则区域定价无法按省市匹配 */
    private function assertCnDivision(array $data): void
    {
        if ($data['country_code'] !== 'CN') {
            return;
        }
        $cities = config('cn_divisions.' . ($data['province'] ?? ''));
        if (($data['province'] ?? '') === '' || !is_array($cities)) {
            throw new BusinessException('请选择正确的省份');
        }
        if (!in_array($data['city'] ?? '', $cities, true)) {
            throw new BusinessException('请选择正确的城市');
        }
    }

    private function makeDefault(int $memberId, int $id): void
    {
        MemberAddress::query()->where('member_id', $memberId)->where('id', '!=', $id)->where('is_default', 1)->update(['is_default' => 0]);
        MemberAddress::query()->where('member_id', $memberId)->where('id', $id)->update(['is_default' => 1]);
    }
}
