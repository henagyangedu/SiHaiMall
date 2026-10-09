<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\AdminToken;
use App\Support\Exceptions\BusinessException;
use App\Support\Paginate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserService
{
    public function list(array $f): array
    {
        $size = min(max((int) ($f['size'] ?? 15), 1), 100);
        $kw = trim((string) ($f['keyword'] ?? ''));

        $paginator = Admin::query()
            ->with('role:id,name')
            ->when($kw !== '', function ($q) use ($kw) {
                $like = '%' . addcslashes($kw, '%_\\') . '%';
                $q->where(fn ($w) => $w->where('username', 'like', $like)
                    ->orWhere('nickname', 'like', $like)
                    ->orWhere('phone', 'like', $like));
            })
            ->when(isset($f['status']) && $f['status'] !== '', fn ($q) => $q->where('status', (int) $f['status']))
            ->orderBy('id')
            ->paginate($size)
            ->through(fn (Admin $a) => [
                'id'            => $a->id,
                'username'      => $a->username,
                'nickname'      => $a->nickname,
                'avatar'        => $a->avatar,
                'phone'         => $a->phone,
                'email'         => $a->email,
                'role_id'       => (int) $a->role_id,
                'role_name'     => $a->isSuper() ? '超级管理员' : ($a->role?->name ?? ''),
                'status'        => (int) $a->status,
                'last_login_at' => $a->last_login_at?->format('Y-m-d H:i:s'),
                'last_login_ip' => $a->last_login_ip,
                'created_at'    => $a->created_at?->format('Y-m-d H:i:s'),
            ]);

        return Paginate::format($paginator);
    }

    public function create(array $d, Admin $op): Admin
    {
        if (Admin::withTrashed()->where('username', $d['username'])->exists()) {
            throw new BusinessException('账号已存在（包括已删除的账号）');
        }
        $roleId = (int) $d['role_id'];
        $this->assertRoleAssignable($roleId, $op);

        return Admin::query()->create([
            'username' => $d['username'],
            'password' => Hash::make($d['password']),
            'nickname' => (string) ($d['nickname'] ?? ''),
            'phone'    => (string) ($d['phone'] ?? ''),
            'email'    => (string) ($d['email'] ?? ''),
            'avatar'   => (string) ($d['avatar'] ?? ''),
            'role_id'  => $roleId,
            'status'   => (int) ($d['status'] ?? 1),
        ]);
    }

    public function update(int $id, array $d, Admin $op): Admin
    {
        $target = $this->find($id);
        $this->assertCanManage($target, $op);

        $roleId = array_key_exists('role_id', $d) ? (int) $d['role_id'] : (int) $target->role_id;
        $status = array_key_exists('status', $d) ? (int) $d['status'] : (int) $target->status;

        if ((int) $target->id === (int) $op->id && ($roleId !== (int) $target->role_id || $status !== (int) $target->status)) {
            throw new BusinessException('不能修改自己的角色或状态');
        }
        if ($roleId !== (int) $target->role_id) {
            $this->assertRoleAssignable($roleId, $op);
        }
        if ($target->isSuper() && ($roleId !== 0 || $status !== 1) && $this->enabledSuperCount() <= 1) {
            throw new BusinessException('至少需要保留一个启用的超级管理员');
        }

        $fill = ['role_id' => $roleId, 'status' => $status];
        foreach (['nickname', 'phone', 'email', 'avatar'] as $field) {
            if (array_key_exists($field, $d)) {
                $fill[$field] = (string) $d[$field];
            }
        }
        if (!empty($d['password'])) {
            $fill['password'] = Hash::make($d['password']);
        }

        $roleChanged = $roleId !== (int) $target->role_id;
        $target->update($fill);

        // 被禁用、换角色或改密码后，强制下线
        if ($status !== 1 || $roleChanged || !empty($d['password'])) {
            AdminToken::query()->where('admin_id', $id)->delete();
        }

        return $target;
    }

    public function delete(int $id, Admin $op): void
    {
        $target = $this->find($id);
        $this->assertCanManage($target, $op);

        if ((int) $target->id === (int) $op->id) {
            throw new BusinessException('不能删除自己');
        }
        if ($target->isSuper() && $this->enabledSuperCount() <= 1) {
            throw new BusinessException('至少需要保留一个启用的超级管理员');
        }

        DB::transaction(function () use ($target, $id) {
            AdminToken::query()->where('admin_id', $id)->delete();
            $target->delete();
        });
    }

    public function resetPassword(int $id, string $newPassword, Admin $op): void
    {
        $target = $this->find($id);
        $this->assertCanManage($target, $op);

        $target->forceFill(['password' => Hash::make($newPassword)])->save();
        AdminToken::query()->where('admin_id', $id)->delete();
    }

    private function find(int $id): Admin
    {
        return Admin::query()->find($id) ?? throw new BusinessException('管理员不存在');
    }

    /** 只有超级管理员能操作超级管理员账号 */
    private function assertCanManage(Admin $target, Admin $op): void
    {
        if ($target->isSuper() && !$op->isSuper()) {
            throw new BusinessException('无权操作超级管理员账号');
        }
    }

    private function assertRoleAssignable(int $roleId, Admin $op): void
    {
        if ($roleId === 0) {
            if (!$op->isSuper()) {
                throw new BusinessException('只有超级管理员才能设置超级管理员');
            }

            return;
        }
        if (!AdminRole::query()->where('id', $roleId)->exists()) {
            throw new BusinessException('所选角色不存在');
        }
    }

    private function enabledSuperCount(): int
    {
        return Admin::query()->where('role_id', 0)->where('status', 1)->count();
    }
}
