<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AdminToken;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(private MenuService $menus) {}

    public function login(string $username, string $password, string $ip, string $ua): array
    {
        $failKey = 'admin_login_fail:' . strtolower($username) . '|' . $ip;
        $lockMinutes = (int) config('admin.lock_minutes');

        if ((int) Cache::get($failKey, 0) >= (int) config('admin.max_login_fail')) {
            throw new BusinessException("登录失败次数过多，请 {$lockMinutes} 分钟后再试", 429);
        }

        $admin = Admin::query()->where('username', $username)->first();
        if (!$admin || !Hash::check($password, $admin->password)) {
            Cache::add($failKey, 0, now()->addMinutes($lockMinutes));
            Cache::increment($failKey);
            throw new BusinessException('账号或密码错误');
        }
        if ((int) $admin->status !== 1) {
            throw new BusinessException('账号已被禁用，请联系管理员');
        }
        Cache::forget($failKey);

        // 顺手清理过期令牌
        AdminToken::query()->where('expires_at', '<', now())->delete();

        $plain = Str::random(64);
        $expires = now()->addHours((int) config('admin.token_ttl_hours'));
        AdminToken::query()->create([
            'admin_id'     => $admin->id,
            'token_hash'   => hash('sha256', $plain),
            'expires_at'   => $expires,
            'last_used_at' => now(),
            'ip'           => $ip,
            'user_agent'   => Str::limit($ua, 250, ''),
        ]);

        $admin->forceFill(['last_login_at' => now(), 'last_login_ip' => $ip])->save();

        return [
            'token'      => $plain,
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'info'       => $this->info($admin),
        ];
    }

    /** 校验令牌，成功返回管理员，同时顺延有效期 */
    public function authenticate(string $plain): ?Admin
    {
        $token = AdminToken::query()
            ->where('token_hash', hash('sha256', $plain))
            ->where('expires_at', '>', now())
            ->first();
        if (!$token) {
            return null;
        }

        $admin = Admin::query()->find($token->admin_id);
        if (!$admin || (int) $admin->status !== 1) {
            return null;
        }

        if (!$token->last_used_at || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->forceFill([
                'last_used_at' => now(),
                'expires_at'   => now()->addHours((int) config('admin.token_ttl_hours')),
            ])->save();
        }

        return $admin;
    }

    public function logout(string $plain): void
    {
        AdminToken::query()->where('token_hash', hash('sha256', $plain))->delete();
    }

    public function changePassword(Admin $admin, string $old, string $new, string $currentPlain): void
    {
        if (!Hash::check($old, $admin->password)) {
            throw new BusinessException('原密码不正确');
        }
        if (Hash::check($new, $admin->password)) {
            throw new BusinessException('新密码不能与原密码相同');
        }

        $admin->forceFill(['password' => Hash::make($new)])->save();

        // 其它设备上的登录全部失效，当前设备保留
        AdminToken::query()
            ->where('admin_id', $admin->id)
            ->where('token_hash', '!=', hash('sha256', $currentPlain))
            ->delete();
    }

    public function info(Admin $admin): array
    {
        $admin->loadMissing('role:id,name');

        return [
            'id'        => $admin->id,
            'username'  => $admin->username,
            'nickname'  => $admin->nickname,
            'avatar'    => $admin->avatar,
            'is_super'  => $admin->isSuper(),
            'role_name' => $admin->isSuper() ? '超级管理员' : ($admin->role?->name ?? ''),
            'menus'     => $this->menus->forAdmin($admin),
            'perms'     => $this->menus->permsForAdmin($admin),
        ];
    }
}
