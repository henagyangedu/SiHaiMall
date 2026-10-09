<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AdminMenu;
use App\Models\AdminRole;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

class MenuService
{
    /** 完整菜单树（后台“菜单规则”和角色授权用） */
    public function tree(): array
    {
        return $this->buildTree($this->allRows(), 0);
    }

    /** 当前管理员可见的菜单（仅目录和菜单，供侧边栏渲染） */
    public function forAdmin(Admin $admin): array
    {
        $query = AdminMenu::query()->whereIn('type', [1, 2])->where('status', 1);

        if (!$admin->isSuper()) {
            if (!$this->roleActive((int) $admin->role_id)) {
                return [];
            }
            $ids = $this->withAncestors($this->menuIdsOfRole((int) $admin->role_id));
            $query->whereIn('id', $ids ?: [0]);
        }

        $rows = $query->orderBy('sort')->orderBy('id')->get()->map->toArray()->all();

        return $this->buildTree($rows, 0);
    }

    /** 当前管理员拥有的权限标识；超级管理员返回 ['*'] */
    public function permsForAdmin(Admin $admin): array
    {
        if ($admin->isSuper()) {
            return ['*'];
        }
        if (!$this->roleActive((int) $admin->role_id)) {
            return [];
        }

        return AdminMenu::query()
            ->whereIn('id', $this->menuIdsOfRole((int) $admin->role_id) ?: [0])
            ->whereIn('type', [2, 3])
            ->where('status', 1)
            ->where('perm', '!=', '')
            ->pluck('perm')
            ->all();
    }

    /** 是否拥有任一权限标识 */
    public function can(Admin $admin, array $perms): bool
    {
        if ($admin->isSuper() || $perms === []) {
            return true;
        }
        if (!$this->roleActive((int) $admin->role_id)) {
            return false;
        }

        return DB::table('admin_role_menu as rm')
            ->join('admin_menu as m', 'm.id', '=', 'rm.menu_id')
            ->where('rm.role_id', (int) $admin->role_id)
            ->where('m.status', 1)
            ->whereIn('m.perm', $perms)
            ->exists();
    }

    public function menuIdsOfRole(int $roleId): array
    {
        return DB::table('admin_role_menu')
            ->where('role_id', $roleId)
            ->pluck('menu_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** 补全祖先节点（授权时子菜单被选中，其父目录必须一并可见） */
    public function withAncestors(array $ids): array
    {
        $pidMap = AdminMenu::query()->pluck('pid', 'id')->all();
        $result = [];
        foreach ($ids as $id) {
            $cur = (int) $id;
            $guard = 0;
            while ($cur > 0 && $guard++ < 20) {
                $result[$cur] = true;
                $cur = (int) ($pidMap[$cur] ?? 0);
            }
        }

        return array_map('intval', array_keys($result));
    }

    public function create(array $d): AdminMenu
    {
        $this->assertParent((int) ($d['pid'] ?? 0), null);
        $this->assertPermUnique((string) ($d['perm'] ?? ''), null);

        return AdminMenu::query()->create($this->payload($d));
    }

    public function update(int $id, array $d): AdminMenu
    {
        $menu = AdminMenu::query()->find($id) ?? throw new BusinessException('菜单不存在');
        $this->assertParent((int) ($d['pid'] ?? 0), $id);
        $this->assertPermUnique((string) ($d['perm'] ?? ''), $id);

        $menu->update($this->payload($d));

        return $menu;
    }

    public function delete(int $id): void
    {
        $menu = AdminMenu::query()->find($id) ?? throw new BusinessException('菜单不存在');
        if (AdminMenu::query()->where('pid', $id)->exists()) {
            throw new BusinessException('请先删除下级菜单');
        }

        DB::transaction(function () use ($menu, $id) {
            DB::table('admin_role_menu')->where('menu_id', $id)->delete();
            $menu->delete();
        });
    }

    private function payload(array $d): array
    {
        return [
            'pid'       => (int) ($d['pid'] ?? 0),
            'type'      => (int) $d['type'],
            'title_key' => (string) $d['title_key'],
            'icon'      => (string) ($d['icon'] ?? ''),
            'path'      => (string) ($d['path'] ?? ''),
            'component' => (string) ($d['component'] ?? ''),
            'perm'      => (string) ($d['perm'] ?? ''),
            'api_rule'  => (string) ($d['api_rule'] ?? ''),
            'sort'      => (int) ($d['sort'] ?? 0),
            'visible'   => (int) ($d['visible'] ?? 1),
            'status'    => (int) ($d['status'] ?? 1),
        ];
    }

    private function assertParent(int $pid, ?int $selfId): void
    {
        if ($pid === 0) {
            return;
        }
        $parent = AdminMenu::query()->find($pid) ?? throw new BusinessException('上级菜单不存在');
        if ((int) $parent->type === 3) {
            throw new BusinessException('权限点下面不能再挂菜单');
        }
        if ($selfId === null) {
            return;
        }
        if ($pid === $selfId) {
            throw new BusinessException('上级菜单不能是自己');
        }
        // 不能挂到自己的后代下面，否则成环
        $pidMap = AdminMenu::query()->pluck('pid', 'id')->all();
        $cur = $pid;
        $guard = 0;
        while ($cur > 0 && $guard++ < 20) {
            if ($cur === $selfId) {
                throw new BusinessException('上级菜单不能是自己的下级');
            }
            $cur = (int) ($pidMap[$cur] ?? 0);
        }
    }

    private function assertPermUnique(string $perm, ?int $selfId): void
    {
        if ($perm === '') {
            return;
        }
        $exists = AdminMenu::query()
            ->where('perm', $perm)
            ->when($selfId !== null, fn ($q) => $q->where('id', '!=', $selfId))
            ->exists();
        if ($exists) {
            throw new BusinessException('权限标识已存在：' . $perm);
        }
    }

    private function roleActive(int $roleId): bool
    {
        return AdminRole::query()->where('id', $roleId)->where('status', 1)->exists();
    }

    private function allRows(): array
    {
        return AdminMenu::query()->orderBy('sort')->orderBy('id')->get()->map->toArray()->all();
    }

    private function buildTree(array $rows, int $pid): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r['pid'] === $pid) {
                $r['children'] = $this->buildTree($rows, (int) $r['id']);
                $out[] = $r;
            }
        }

        return $out;
    }
}
