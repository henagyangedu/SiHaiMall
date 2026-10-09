<?php

namespace App\Services\Admin;

use App\Models\MemberLevel;
use App\Services\Common\TranslationService;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

class MemberLevelService
{
    public function __construct(private TranslationService $trans) {}

    public function list(): array
    {
        $rows = MemberLevel::query()->orderBy('level_no')->get();
        $langs = $this->trans->fetch('member_level_lang', 'level_id', $rows->pluck('id')->all(), ['name']);
        $counts = DB::table('member')->whereNull('deleted_at')->groupBy('level_id')->selectRaw('level_id, count(*) as c')->pluck('c', 'level_id');

        return $rows->map(function (MemberLevel $l) use ($langs, $counts) {
            $names = $langs[$l->id]['name'] ?? [];

            return [
                'id' => (int) $l->id, 'level_no' => (int) $l->level_no, 'icon' => $l->icon,
                'need_points' => (int) $l->need_points, 'discount' => (string) $l->discount, 'status' => (int) $l->status,
                'name' => $names, 'name_text' => $this->trans->resolve($names),
                'member_count' => (int) ($counts[$l->id] ?? 0),
            ];
        })->all();
    }

    /** 启用的等级下拉（会员筛选、编辑用） */
    public function options(): array
    {
        $rows = MemberLevel::query()->orderBy('level_no')->get(['id', 'status']);
        $langs = $this->trans->fetch('member_level_lang', 'level_id', $rows->pluck('id')->all(), ['name']);

        return $rows->map(fn ($l) => [
            'id' => (int) $l->id, 'status' => (int) $l->status,
            'name' => $this->trans->resolve($langs[$l->id]['name'] ?? []),
        ])->all();
    }

    public function create(array $d): MemberLevel
    {
        $name = (array) ($d['name'] ?? []);
        $this->trans->assertDefaultFilled($name, '等级名称');
        $this->assertNoUnique((int) $d['level_no'], 0);

        return DB::transaction(function () use ($d, $name) {
            $l = MemberLevel::query()->create($this->fields($d));
            $this->trans->sync('member_level_lang', 'level_id', (int) $l->id, ['name' => $name], ['name']);

            return $l;
        });
    }

    public function update(int $id, array $d): void
    {
        $l = $this->find($id);
        $name = (array) ($d['name'] ?? []);
        $this->trans->assertDefaultFilled($name, '等级名称');
        $this->assertNoUnique((int) $d['level_no'], $id);

        if ((int) ($d['status'] ?? 1) === 0 && !MemberLevel::query()->where('id', '!=', $id)->where('status', 1)->exists()) {
            throw new BusinessException('至少保留一个启用的等级');
        }

        DB::transaction(function () use ($l, $id, $d, $name) {
            $l->update($this->fields($d));
            $this->trans->sync('member_level_lang', 'level_id', $id, ['name' => $name], ['name']);
        });
    }

    public function delete(int $id): void
    {
        $l = $this->find($id);
        if ($id === 1) {
            throw new BusinessException('默认等级不能删除');
        }
        if (DB::table('member')->where('level_id', $id)->whereNull('deleted_at')->exists()) {
            throw new BusinessException('该等级下还有会员，请先调整这些会员的等级');
        }

        DB::transaction(function () use ($l, $id) {
            DB::table('member_level_lang')->where('level_id', $id)->delete();
            $l->delete();
        });
    }

    private function fields(array $d): array
    {
        return [
            'level_no'    => (int) $d['level_no'],
            'icon'        => (string) ($d['icon'] ?? ''),
            'need_points' => (int) ($d['need_points'] ?? 0),
            'discount'    => $d['discount'] ?? 100,
            'status'      => (int) ($d['status'] ?? 1),
        ];
    }

    private function assertNoUnique(int $levelNo, int $exceptId): void
    {
        if (MemberLevel::query()->where('level_no', $levelNo)->when($exceptId > 0, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            throw new BusinessException('等级序号已存在');
        }
    }

    private function find(int $id): MemberLevel
    {
        return MemberLevel::query()->find($id) ?? throw new BusinessException('会员等级不存在');
    }
}
