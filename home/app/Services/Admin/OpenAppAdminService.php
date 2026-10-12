<?php

namespace App\Services\Admin;

use App\Models\OpenApp;
use App\Models\OpenLog;
use App\Models\OpenWebhook;
use App\Services\Common\OpenAppService;
use App\Services\Common\OpenWebhookService;
use App\Support\Exceptions\BusinessException;
use App\Support\OpenEvents;
use App\Support\OpenScopes;
use App\Support\Paginate;

/** 后台“设置 → 开放接口”：应用的增删改、重新生成密钥、查看调用记录，以及主动推送的设置、推送记录、重发 */
class OpenAppAdminService
{
    public function __construct(private OpenAppService $apps, private OpenWebhookService $webhooks) {}

    public function list(): array
    {
        return OpenApp::query()->orderByDesc('id')->get()->map(fn (OpenApp $a) => $this->row($a))->all();
    }

    /** 权限的选项，给表单用 */
    public function scopeOptions(): array
    {
        return collect(OpenScopes::ALL)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values()->all();
    }

    /** 能订阅的事件，给表单用。scope 是应用要有的权限 */
    public function eventOptions(): array
    {
        return collect(OpenEvents::ALL)->map(fn ($v, $code) => ['code' => $code, 'label' => $v[0], 'scope' => $v[1]])->values()->all();
    }

    /**
     * 新建应用。返回里的 key 是密钥明文，只有这一次机会看到；填了推送地址时还有 webhook_secret（推送的签名密钥），同样只出现这一次。
     * @param array{name:string,scopes:string[],ip_whitelist?:?string,status?:int|string|null,remark?:?string,webhook_url?:?string,webhook_events?:?array,webhook_status?:int|string|null} $d
     */
    public function create(array $d): array
    {
        [$plain, $prefix, $hash] = $this->apps->newKey();
        $fields = $this->fields($d);
        $secret = $fields['webhook_url'] !== '' ? $this->webhooks->newSecret() : null;
        $app = OpenApp::query()->create($fields + ['key_prefix' => $prefix, 'key_hash' => $hash, 'webhook_secret' => $secret]);

        return $this->row($app) + ['key' => $plain, 'webhook_secret' => $secret];
    }

    /** 第一次填推送地址时生成签名密钥，放在返回的 webhook_secret 里（只出现这一次）；其他时候是 null */
    public function update(int $id, array $d): array
    {
        $app = $this->find($id);
        $fields = $this->fields($d);
        $secret = $fields['webhook_url'] !== '' && (string) $app->webhook_secret === '' ? $this->webhooks->newSecret() : null;
        $app->update($fields + ($secret ? ['webhook_secret' => $secret] : []));

        return ['webhook_secret' => $secret];
    }

    /** 重新生成密钥：旧密钥马上失效 */
    public function resetKey(int $id): array
    {
        $app = $this->find($id);
        [$plain, $prefix, $hash] = $this->apps->newKey();
        $app->update(['key_prefix' => $prefix, 'key_hash' => $hash]);

        return ['key' => $plain];
    }

    /** 重新生成推送的签名密钥：之后发出的通知马上改用新的 */
    public function resetWebhookSecret(int $id): array
    {
        $secret = $this->webhooks->newSecret();
        $this->find($id)->update(['webhook_secret' => $secret]);

        return ['webhook_secret' => $secret];
    }

    public function delete(int $id): void
    {
        $this->find($id)->delete();
        OpenLog::query()->where('app_id', $id)->delete();
        OpenWebhook::query()->where('app_id', $id)->delete();
    }

    /** 一个应用的写操作记录，新的在前 */
    public function logs(int $id, int $page, int $size): array
    {
        $this->find($id);

        return Paginate::format(OpenLog::query()->where('app_id', $id)->orderByDesc('id')->paginate(min(100, max(1, $size)), ['*'], 'page', max(1, $page)));
    }

    /** 一个应用的推送记录，新的在前 */
    public function webhooks(int $id, int $page, int $size): array
    {
        $this->find($id);
        $p = OpenWebhook::query()->where('app_id', $id)->orderByDesc('id')->paginate(min(100, max(1, $size)), ['*'], 'page', max(1, $page));

        return ['list' => collect($p->items())->map(fn (OpenWebhook $w) => $this->webhookRow($w))->all(), 'total' => $p->total(), 'page' => $p->currentPage(), 'size' => $p->perPage()];
    }

    /** 手工重发一条通知（编号不变，对方按编号去重）。返回这一次的结果 */
    public function resend(int $id, int $webhookId): array
    {
        $w = OpenWebhook::query()->where('app_id', $id)->find($webhookId) ?? throw new BusinessException('推送记录不存在');
        $this->webhooks->resend($w);

        return $this->webhookRow($w->refresh());
    }

    /** 发一条测试通知（事件 ping），当场给出结果。只要填了接收地址就能发，不用先开启推送 */
    public function testWebhook(int $id): array
    {
        $app = $this->find($id);
        if ((string) $app->webhook_url === '') {
            throw new BusinessException('请先填写接收地址并保存');
        }
        $w = $this->webhooks->record($app, OpenEvents::PING, '', ['message' => '这是一条测试通知']);
        $this->webhooks->resend($w);

        return $this->webhookRow($w->refresh());
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
        ] + $this->webhookFields($d, $scopes);
    }

    /** 主动推送的设置。开启时地址和事件都要有；事件要求的权限这个应用得有 */
    private function webhookFields(array $d, array $scopes): array
    {
        $url = trim((string) ($d['webhook_url'] ?? ''));
        $on = (int) ($d['webhook_status'] ?? 0) === 1;
        $events = array_values(array_unique(array_intersect((array) ($d['webhook_events'] ?? []), array_keys(OpenEvents::ALL))));
        if ($url !== '' && ($problem = $this->webhooks->urlProblem($url))) {
            throw new BusinessException($problem);
        }
        foreach ($events as $e) {
            [$label, $scope] = OpenEvents::ALL[$e];
            if (!in_array($scope, $scopes, true)) {
                throw new BusinessException("订阅“{$label}”需要先勾选权限“" . OpenScopes::ALL[$scope] . '”');
            }
        }
        if ($on && $url === '') {
            throw new BusinessException('开启主动推送要先填写接收地址');
        }
        if ($on && $events === []) {
            throw new BusinessException('开启主动推送要至少订阅一个事件');
        }

        return ['webhook_url' => $url, 'webhook_events' => $events, 'webhook_status' => $on ? 1 : 0];
    }

    private function row(OpenApp $a): array
    {
        return [
            'id' => (int) $a->id, 'name' => (string) $a->name,
            // 只给开头几位，用来辨认是哪一把；完整的密钥库里没有
            'key_hint' => $a->key_prefix . '…',
            'scopes' => array_values((array) $a->scopes), 'ip_whitelist' => (string) $a->ip_whitelist,
            'status' => (int) $a->status, 'remark' => (string) $a->remark,
            'webhook_url' => (string) $a->webhook_url, 'webhook_events' => array_values((array) $a->webhook_events), 'webhook_status' => (int) $a->webhook_status,
            'last_used_at' => $a->last_used_at?->format('Y-m-d H:i:s'), 'created_at' => $a->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function webhookRow(OpenWebhook $w): array
    {
        return [
            'id' => (int) $w->id, 'event_id' => (string) $w->event_id, 'event' => (string) $w->event, 'ref_no' => (string) $w->ref_no,
            'status' => (int) $w->status, 'status_text' => OpenWebhook::STATUS_TEXT[(int) $w->status] ?? '', 'attempts' => (int) $w->attempts,
            'last_code' => (int) $w->last_code, 'last_error' => (string) $w->last_error, 'payload' => (string) $w->payload,
            'next_at' => $w->next_at?->format('Y-m-d H:i:s'), 'created_at' => $w->created_at?->format('Y-m-d H:i:s'), 'delivered_at' => $w->delivered_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function find(int $id): OpenApp
    {
        return OpenApp::query()->find($id) ?? throw new BusinessException('应用不存在');
    }
}
