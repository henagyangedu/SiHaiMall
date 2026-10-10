<?php

namespace App\Services\Common;

use App\Drivers\DriverManager;
use App\Models\Shipping;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 物流轨迹查询（买家订单详情、后台订单详情共用）。
 *
 * 查询接口按次计费，所以：
 * - 结果缓存在发货记录上（gm_shipping.track_status / track_data），30 分钟内不重复查；已签收的不再查。
 * - 后台可以“刷新”，但同一个包裹 5 分钟内最多真正查一次。
 * - 没启用物流查询、这家快递不支持、或服务商出故障时不报错，返回已有的缓存并带上 message 说明原因。
 * - 可以同时启用几个服务商：按 DriverManager::expressList 的顺序（快递100 在前、17TRACK 垫底），用第一个能查这家快递的。
 * - 服务商说“刚登记、还在抓取”（结果带 pending）时，缓存只留 5 分钟，免得买家要等半小时才看得到。
 */
class ExpressTrackService
{
    /** 轨迹状态：0 暂无 1 运输中 2 已签收 3 异常 */
    public const SIGNED = 2;

    private const CACHE_MINUTES = 30;

    private const FORCE_MINUTES = 5;

    /** 同一个包裹连续查询失败后的冷却（秒），避免服务商故障时每次打开订单都去请求 */
    private const FAIL_COOLDOWN = 120;

    private const PENDING_MESSAGE = '正在获取物流信息，一般几分钟后可以看到';

    public function __construct(private DriverManager $drivers) {}

    public function enabled(): bool
    {
        return DB::table('driver_config')->where('type', 'express')->where('is_enabled', 1)->exists();
    }

    /**
     * 一个包裹的物流轨迹。
     * @return array{id:int,express_code:string,express_name:string,express_no:string,shipped_at:?string,status:int,traces:array<int,array{time:string,context:string}>,queried_at:?string,message:string}
     */
    public function track(Shipping $s, bool $force = false): array
    {
        $cache = json_decode((string) ($s->track_data ?: ''), true) ?: [];
        $pending = !empty($cache['pending']);
        $out = [
            'id' => (int) $s->id, 'express_code' => (string) $s->express_code, 'express_name' => (string) $s->express_name, 'express_no' => (string) $s->express_no,
            'shipped_at' => $s->shipped_at?->format('Y-m-d H:i:s'),
            'status' => (int) $s->track_status, 'traces' => (array) ($cache['traces'] ?? []), 'queried_at' => $cache['queried_at'] ?? null, 'message' => '',
        ];

        if ($out['status'] === self::SIGNED) {
            return $out;
        }
        $age = $out['queried_at'] ? now()->diffInMinutes($out['queried_at'], true) : null;
        if ($age !== null && $age < ($force || $pending ? self::FORCE_MINUTES : self::CACHE_MINUTES)) {
            return ($pending && $out['traces'] === [] ? ['message' => self::PENDING_MESSAGE] : []) + $out;
        }
        if (!$this->enabled()) {
            return ['message' => '商家还没有开通物流查询，可以复制单号到快递公司官网查询'] + $out;
        }
        $failKey = 'express_fail:' . $s->id;
        if (Cache::has($failKey)) {
            return ['message' => '物流查询服务暂时不可用，请稍后再试'] + $out;
        }

        try {
            $driver = collect($this->drivers->expressList())->first(fn ($d) => $d->supports((string) $s->express_code));
            if (!$driver) {
                return ['message' => '这家快递公司暂不支持在线查询，可以复制单号到快递公司官网查询'] + $out;
            }
            $r = $driver->query((string) $s->express_code, (string) $s->express_no, $this->phone((int) $s->order_id));
        } catch (BusinessException $e) {
            Cache::put($failKey, 1, self::FAIL_COOLDOWN);

            return ['message' => $e->getMessage()] + $out;
        }

        // 服务商偶尔会返回比上次更少的轨迹（节点延迟），这种情况保留已有的
        $traces = count($r['traces']) >= count($out['traces']) ? $r['traces'] : $out['traces'];
        $status = $r['status'] === 0 && $out['status'] !== 0 ? $out['status'] : $r['status'];
        $queriedAt = now()->format('Y-m-d H:i:s');
        Shipping::query()->whereKey($s->id)->update([
            'track_status' => $status,
            'track_data' => json_encode(['queried_at' => $queriedAt, 'traces' => $traces] + ($traces === [] && !empty($r['pending']) ? ['pending' => true] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $message = match (true) {
            $traces !== [] => '',
            !empty($r['pending']) => self::PENDING_MESSAGE,
            default => '暂时还没有物流信息，请稍后再看',
        };

        return ['status' => $status, 'traces' => $traces, 'queried_at' => $queriedAt, 'message' => $message] + $out;
    }

    /**
     * 一张订单所有包裹的物流轨迹。
     * @return array<int,array<string,mixed>>
     */
    public function forOrder(int $orderId): array
    {
        return Shipping::query()->where('order_id', $orderId)->orderBy('id')->get()->map(fn (Shipping $s) => $this->track($s))->all();
    }

    /** 收件人手机号（部分快递公司查询时校验尾号） */
    private function phone(int $orderId): string
    {
        return (string) DB::table('order_address')->where('order_id', $orderId)->value('phone');
    }
}
