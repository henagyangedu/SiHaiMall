<?php

namespace App\Services\Common;

use App\Models\Admin;
use App\Models\OpenApp;
use Illuminate\Support\Str;

/** 开放接口应用的密钥：生成、校验。密钥只在生成时出现一次，库里只存 sha256 */
class OpenAppService
{
    /** 密钥的固定开头，方便对接方和日志里一眼认出这是商城的开放接口密钥 */
    public const PREFIX = 'shm_';

    /** @return array{0:string,1:string,2:string} 明文密钥、列表里显示的开头几位、哈希 */
    public function newKey(): array
    {
        $plain = self::PREFIX . Str::random(40);

        return [$plain, substr($plain, 0, 10), hash('sha256', $plain)];
    }

    /**
     * 按请求里的密钥找应用。返回 [应用, 拒绝的原因]：成功时原因是空串。
     * @return array{0:?OpenApp,1:string}
     */
    public function authenticate(?string $plain, string $ip): array
    {
        if ($plain === null || $plain === '' || !str_starts_with($plain, self::PREFIX)) {
            return [null, '缺少密钥或密钥无效'];
        }
        $app = OpenApp::query()->where('key_hash', hash('sha256', $plain))->first();
        if (!$app) {
            return [null, '缺少密钥或密钥无效'];
        }
        if ((int) $app->status !== 1) {
            return [null, '这个应用已停用'];
        }
        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) $app->ip_whitelist))));
        if ($allowed !== [] && !in_array($ip, $allowed, true)) {
            return [null, "IP {$ip} 不在这个应用的白名单里"];
        }
        // 最近使用时间只是给管理员看个大概，一分钟内不重复写
        if (!$app->last_used_at || $app->last_used_at->lt(now()->subMinute())) {
            OpenApp::query()->whereKey($app->id)->update(['last_used_at' => now()]);
        }

        return [$app, ''];
    }

    /**
     * 现有的发货、调库存服务要一个“操作人”（管理员）。开放接口没有管理员，给一个不落库的：ID 是 0，名字写明是哪个应用。
     */
    public function operator(OpenApp $app): Admin
    {
        return new Admin(['username' => $this->label($app)]);
    }

    /** 写进订单日志、库存日志里的来源说明 */
    public function label(OpenApp $app): string
    {
        return "开放接口（{$app->name}）";
    }
}
