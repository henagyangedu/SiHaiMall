<?php

namespace App\Services\Admin;

use App\Models\OpenApp;
use App\Models\OpenLog;
use App\Services\Common\OpenAppService;
use App\Support\Exceptions\BusinessException;
use App\Support\OpenScopes;
use App\Support\Paginate;

/** 后台“设置 → 开放接口”：应用的增删改、重新生成密钥、查看调用记录 */
class OpenAppAdminService
{
    public function __construct(private OpenAppService $apps) {}

    public function list(): array
    {
        return OpenApp::query()->orderByDesc('id')->get()->map(fn (OpenApp $a) => $this->row($a))->all();
    }

    /** 权限的选项，给表单用 */
    public function scopeOptions(): array
    {
        return collect(OpenScopes::ALL)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values()->all();
    }

    /**
     * 新建应用。返回里的 key 是密钥明文，只有这一次机会看到。
     * @param array{name:string,scopes:string[],ip_whitelist?:?string,status?:int|string|null,remark?:?string} $d
     */
    public function create(array $d): array
    {
        [$plain, $prefix, $hash] = $this->apps->newKey();
        $app = OpenApp::query()->create($this->fields($d) + ['key_prefix' => $prefix, 'key_hash' => $hash]);

        return $this->row($app) + ['key' => $plain];
    }

    public function update(int $id, array $d): void
    {
        $this->find($id)->update($this->fields($d));
    }

    /** 重新生成密钥：旧密钥马上失效 */
    public function resetKey(int $id): array
    {
        $app = $this->find($id);
        [$plain, $prefix, $hash] = $this->apps->newKey();
        $app->update(['key_prefix' => $prefix, 'key_hash' => $hash]);

        return ['key' => $plain];
    }

    public function delete(int $id): void
    {
        $this->find($id)->delete();
        OpenLog::query()->where('app_id', $id)->delete();
    }

    /** 一个应用的写操作记录，新的在前 */
    public function logs(int $id, int $page, int $size): array
    {
        $this->find($id);

        return Paginate::format(OpenLog::query()->where('app_id', $id)->orderByDesc('id')->paginate(min(100, max(1, $size)), ['*'], 'page', max(1, $page)));
    }

    private function fields(array $d): array
    {
        $scopes = array_values(array_unique(array_intersect((array) $d['scopes'], array_keys(OpenScopes::ALL))));
        if ($scopes === []) {
            throw new BusinessException('请至少选择一项权限');
        }
        $ips = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,，;；]+/u', (string) ($d['ip_whitelist'] ?? ''))))));
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new BusinessException("IP 白名单里的“{$ip}”不是有效的 IP 地址（不支持网段）");
            }
        }
        $list = implode(',', $ips);
        if (strlen($list) > 500) {
            throw new BusinessException('IP 白名单太长了');
        }

        return [
            'name' => trim((string) $d['name']), 'scopes' => $scopes, 'ip_whitelist' => $list,
            'status' => (int) ($d['status'] ?? 1) === 1 ? 1 : 0, 'remark' => trim((string) ($d['remark'] ?? '')),
        ];
    }

    private function row(OpenApp $a): array
    {
        return [
            'id' => (int) $a->id, 'name' => (string) $a->name,
            // 只给开头几位，用来辨认是哪一把；完整的密钥库里没有
            'key_hint' => $a->key_prefix . '…',
            'scopes' => array_values((array) $a->scopes), 'ip_whitelist' => (string) $a->ip_whitelist,
            'status' => (int) $a->status, 'remark' => (string) $a->remark,
            'last_used_at' => $a->last_used_at?->format('Y-m-d H:i:s'), 'created_at' => $a->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function find(int $id): OpenApp
    {
        return OpenApp::query()->find($id) ?? throw new BusinessException('应用不存在');
    }
}
