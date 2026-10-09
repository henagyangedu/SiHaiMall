<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AdminMenu;
use App\Models\AdminRole;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;

class RoleService
{
    public function __construct(private MenuService $menus) {}

    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);

        $paginator = AdminRole::query()
            ->withCount('admins')
            ->orderBy('id')
            ->paginate($size);

        return Paginate::format($paginator);
    }

    public function options(): array
    {
        return AdminRole::query()->where('status', 1)->orderBy('id')->get(['id', 'name'])->toArray();
    }

    public function detail(int $id): array
    {
        $role = AdminRole::query()->find($id) ?? throw new BusinessException('角色不存在');

        return [
            'id'       => $role->id,
            'name'     => $role->name,
            'remark'   => $role->remark,
            'status'   => (int) $role->status,
            'menu_ids' => $this->menus->menuIdsOfRole($id),
        ];
    }

    public function create(array $d, Admin $op): AdminRole
    {
        return DB::transaction(function () use ($d, $op) {
            $this->assertNameUnique($d['name'], null);
            $role = AdminRole::query()->create([
                'name'   => $d['name'],
                'remark' => (string) ($d['remark'] ?? ''),
                'status' => (int) ($d['status'] ?? 1),
            ]);
            $this->syncMenus((int) $role->id, $d['menu_ids'] ?? [], $op);

            return $role;
        });
    }

    public function update(int $id, array $d, Admin $op): AdminRole
    {
        $role = AdminRole::query()->find($id) ?? throw new BusinessException('角色不存在');
        if (!$op->isSuper() && (int) $op->role_id === $id) {
            throw new BusinessException('不能修改自己所属的角色');
        }

        return DB::transaction(function () use ($role, $id, $d, $op) {
            $this->assertNameUnique($d['name'], $id);
            $role->update([
                'name'   => $d['name'],
                'remark' => (string) ($d['remark'] ?? ''),
                'status' => (int) ($d['status'] ?? $role->status),
            ]);
            if (array_key_exists('menu_ids', $d)) {
                $this->syncMenus($id, $d['menu_ids'] ?? [], $op);
            }

            return $role;
        });
    }

    public function delete(int $id): void
    {
        $role = AdminRole::query()->find($id) ?? throw new BusinessException('角色不存在');
        if (Admin::withTrashed()->where('role_id', $id)->exists()) {
            throw new BusinessException('该角色下仍有管理员，请先调整或删除这些管理员');
        }

        DB::transaction(function () use ($role, $id) {
            DB::table('admin_role_menu')->where('role_id', $id)->delete();
            $role->delete();
        });
    }

    private function assertNameUnique(string $name, ?int $selfId): void
    {
        $exists = AdminRole::query()
            ->where('name', $name)
            ->when($selfId !== null, fn ($q) => $q->where('id', '!=', $selfId))
            ->exists();
        if ($exists) {
            throw new BusinessException('角色名称已存在');
        }
    }

    /** 保存角色菜单；非超管不能授予超出自己权限范围的菜单（防止越权提权） */
    private function syncMenus(int $roleId, array $ids, Admin $op): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids !== []) {
            $valid = AdminMenu::query()->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
            if (count($valid) !== count($ids)) {
                throw new BusinessException('包含不存在的菜单');
            }
            if (!$op->isSuper()) {
                $own = $this->menus->withAncestors($this->menus->menuIdsOfRole((int) $op->role_id));
                if (array_diff($ids, $own) !== []) {
                    throw new BusinessException('不能授予超出自身权限的菜单');
                }
            }
        }

        DB::table('admin_role_menu')->where('role_id', $roleId)->delete();
        if ($ids !== []) {
            DB::table('admin_role_menu')->insert(
                array_map(fn (int $mid) => ['role_id' => $roleId, 'menu_id' => $mid], $ids)
            );
        }
    }
}
