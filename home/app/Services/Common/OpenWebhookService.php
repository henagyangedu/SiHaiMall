<?php

namespace App\Services\Common;

use App\Models\OpenApp;
use App\Models\OpenWebhook;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Shipping;
use App\Support\OpenEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 开放接口的主动推送（Webhook）。
 * 业务节点调 order() / shipped() / refund()：在业务的事务里先把通知写进 gm_open_webhook（业务回滚，通知也跟着没有），
 * 事务提交、响应返回之后马上发一次；没发成功的由 mall:webhook-deliver 按间隔重试。
 * 通知是“薄”的：只带单号和当时的状态，详情让对方用订单 / 售后接口再取。
 */
class OpenWebhookService
{
    /** 第一次没发成功之后，隔多少分钟再试；试完这几次还不行就标为失败，等人工重发 */
    public const RETRY_MINUTES = [1, 5, 30, 120, 360, 1440];

    /** 等对方应答的秒数 */
    public const TIMEOUT = 5;

    /** 推送记录保留多少天 */
    public const KEEP_DAYS = 30;

    public const SECRET_PREFIX = 'whsec_';

    /** 订单事件 */
    public function order(string $event, Order $o): void
    {
        $this->emit($event, (string) $o->order_no, $this->orderData($o));
    }

    /** 发了一个包裹 */
    public function shipped(Order $o, Shipping $s): void
    {
        $this->emit(OpenEvents::ORDER_SHIPPED, (string) $o->order_no, $this->orderData($o) + [
            'shipment_id' => (int) $s->id, 'express_code' => (string) $s->express_code, 'express_name' => (string) $s->express_name,
            'express_no' => (string) $s->express_no,
        ]);
    }

    /** 售后事件 */
    public function refund(string $event, OrderRefund $r, Order $o): void
    {
        $this->emit($event, (string) $r->refund_no, [
            'refund_no' => (string) $r->refund_no, 'order_no' => (string) $o->order_no,
            'type' => (int) $r->type, 'status' => (int) $r->status,
            'refund_amount' => (string) $r->refund_amount, 'currency' => (string) $r->currency,
        ]);
    }

    /**
     * 给订阅了这个事件的每个应用记一条通知。没有应用订阅时只多一次很小的查询。
     * @param array<string,mixed> $data
     */
    public function emit(string $event, string $refNo, array $data): void
    {
        $scope = OpenEvents::ALL[$event][1] ?? null;
        if ($scope === null) {
            return;
        }
        $ids = [];
        foreach (OpenApp::query()->where('status', 1)->where('webhook_status', 1)->where('webhook_url', '<>', '')->get() as $app) {
            if (in_array($event, (array) $app->webhook_events, true) && $app->can($scope)) {
                $ids[] = (int) $this->record($app, $event, $refNo, $data)->id;
            }
        }
        if ($ids === []) {
            return;
        }
        // 提交之后、响应返回之后再发：对方慢或者挂了，不拖慢买家下单付款
        DB::afterCommit(function () use ($ids) {
            defer(function () use ($ids) {
                foreach ($ids as $id) {
                    try {
                        $this->deliver($id, true);
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            });
        });
    }

    /** 写一条待发送的通知。正文在这里定下来，重试、重发的都是这一份 */
    public function record(OpenApp $app, string $event, string $refNo, array $data): OpenWebhook
    {
        $id = str_replace('-', '', (string) Str::uuid());
        $now = now();

        return OpenWebhook::query()->create([
            'app_id' => $app->id, 'event_id' => $id, 'event' => $event, 'ref_no' => mb_substr($refNo, 0, 40),
            'payload' => json_encode(['id' => $id, 'event' => $event, 'created_at' => $now->format('Y-m-d H:i:s'), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => OpenWebhook::PENDING, 'attempts' => 0,
            // 马上那一次由提交后的回调去发；万一那一步没跑成（进程被杀），一分钟后定时任务接手
            'next_at' => $now->copy()->addMinute(), 'created_at' => $now,
        ]);
    }

    /**
     * 发一条通知。先用带条件的更新把它“占住”（次数加一、下次时间往后推），所以马上发的那一次和定时任务不会把同一条发两遍。
     * @param bool $first true = 事务提交后马上发的那一次（只发从没试过的）；false = 定时任务（只发到了时间的）
     * @return bool 这一次有没有发成功
     */
    public function deliver(int $id, bool $first = false): bool
    {
        $claimed = OpenWebhook::query()->whereKey($id)->where('status', OpenWebhook::PENDING)
            ->when($first, fn ($q) => $q->where('attempts', 0), fn ($q) => $q->where('next_at', '<=', now()))
            ->update(['attempts' => DB::raw('attempts + 1'), 'next_at' => now()->addMinutes(2)]);
        if ($claimed !== 1) {
            return false;
        }

        return $this->send(OpenWebhook::query()->findOrFail($id));
    }

    /** 定时任务：把到了时间的都发一遍。返回 [成功几条, 失败几条] */
    public function deliverDue(int $limit = 200): array
    {
        $ok = $fail = 0;
        $ids = OpenWebhook::query()->where('status', OpenWebhook::PENDING)->where('next_at', '<=', now())->orderBy('next_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try {
                $this->deliver((int) $id) ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                report($e);
                $fail++;
            }
        }

        return [$ok, $fail];
    }

    /** 后台手工重发：不管原来是什么状态，现在就发一次（编号不变，对方按编号去重） */
    public function resend(OpenWebhook $w): bool
    {
        OpenWebhook::query()->whereKey($w->id)->update(['status' => OpenWebhook::PENDING, 'attempts' => DB::raw('attempts + 1'), 'next_at' => now()->addMinutes(2)]);

        return $this->send($w->refresh(), true);
    }

    /** 删掉超过保留期的推送记录 */
    public function cleanup(): int
    {
        return OpenWebhook::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    public function newSecret(): string
    {
        return self::SECRET_PREFIX . Str::random(40);
    }

    /** 签名：HMAC-SHA256(密钥, "时间戳.正文")，十六进制 */
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * 接收地址能不能用。返回不能用的原因，能用返回 null。
     * 生产环境只允许 https，并且不能指向内网或本机（否则可以借这个功能探测内网）。
     * @param string[]|null $ips 传出解析到的 IP（地址本身就是 IP 时是它自己）
     */
    public function urlProblem(string $url, ?array &$ips = null): ?string
    {
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = trim((string) ($p['host'] ?? ''), '[]');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($p['user'])) {
            return '接收地址必须是 https:// 开头的完整地址';
        }
        if ($this->allowPrivate()) {
            return null;
        }
        if ($scheme !== 'https') {
            return '接收地址必须是 https:// 开头的完整地址';
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return "接收地址的域名 {$host} 解析不出来";
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return '接收地址不能指向内网或本机';
            }
        }

        return null;
    }

    private function allowPrivate(): bool
    {
        $set = config('mall.webhook_allow_private');

        // .env 里留空（OPEN_WEBHOOK_ALLOW_PRIVATE=）读出来是空字符串，同样算没设置
        return $set === null || $set === '' ? !app()->environment('production') : filter_var($set, FILTER_VALIDATE_BOOL);
    }

    /** 真正发出去，并把结果记到这条通知上 */
    private function send(OpenWebhook $w, bool $manual = false): bool
    {
        $app = OpenApp::query()->find($w->app_id);
        // 应用停用、关了推送、删了地址：不发，也不再重试。测试通知只要有地址就发（开启之前先试一下通不通）
        $open = $w->event === OpenEvents::PING || ($app && (int) $app->status === 1 && (int) $app->webhook_status === 1);
        if (!$app || !$open || (string) $app->webhook_url === '') {
            return $this->failed($w, 0, '应用已停用或已关闭推送', true);
        }
        $url = (string) $app->webhook_url;
        $ips = null;
        if ($problem = $this->urlProblem($url, $ips)) {
            return $this->failed($w, 0, $problem, $manual);
        }

        $ts = time();
        $body = (string) $w->payload;
        $request = Http::timeout(self::TIMEOUT)->connectTimeout(self::TIMEOUT)->withoutRedirecting()
            ->withHeaders([
                'User-Agent' => 'SihaiMall-Webhook/1', 'X-Shm-Event' => $w->event, 'X-Shm-Delivery' => $w->event_id,
                'X-Shm-Timestamp' => (string) $ts, 'X-Shm-Signature' => 'sha256=' . self::sign((string) $app->webhook_secret, $ts, $body),
            ])->withBody($body, 'application/json');
        // 检查过的是这几个 IP，就连这几个：不让域名在检查之后换成内网地址
        if ($ips) {
            $p = parse_url($url);
            $request = $request->withOptions(['curl' => [CURLOPT_RESOLVE => [$p['host'] . ':' . ($p['port'] ?? 443) . ':' . implode(',', $ips)]]]);
        }

        try {
            $res = $request->post($url);
        } catch (\Throwable $e) {
            return $this->failed($w, 0, '连接失败：' . $e->getMessage(), $manual);
        }
        if (!$res->successful()) {
            return $this->failed($w, $res->status(), mb_substr(trim($res->body()), 0, 200), $manual);
        }
        OpenWebhook::query()->whereKey($w->id)->update([
            'status' => OpenWebhook::SUCCESS, 'last_code' => $res->status(), 'last_error' => '', 'next_at' => null, 'delivered_at' => now(),
        ]);

        return true;
    }

    /** @param bool $final true = 不再自动重试 */
    private function failed(OpenWebhook $w, int $code, string $error, bool $final): bool
    {
        // attempts 在占住的时候已经加过一：第 1 次失败后等 RETRY_MINUTES[0] 分钟，以此类推
        $wait = self::RETRY_MINUTES[(int) $w->attempts - 1] ?? null;
        $final = $final || $wait === null;
        OpenWebhook::query()->whereKey($w->id)->update([
            'status' => $final ? OpenWebhook::FAILED : OpenWebhook::PENDING, 'last_code' => $code, 'last_error' => mb_substr($error, 0, 300),
            'next_at' => $final ? null : Carbon::now()->addMinutes($wait),
        ]);

        return false;
    }

    /** 订单通知里带的内容：单号和发出通知那一刻的状态 */
    private function orderData(Order $o): array
    {
        return [
            'order_no' => (string) $o->order_no, 'status' => (int) $o->status, 'pay_status' => (int) $o->pay_status,
            'pay_amount' => (string) $o->pay_amount, 'currency' => (string) $o->currency,
        ];
    }
}
