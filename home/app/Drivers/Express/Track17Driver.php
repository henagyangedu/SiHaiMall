<?php

namespace App\Drivers\Express;

use App\Drivers\Contracts\ExpressDriver;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 17TRACK 查询接口（api.17track.net/track/v2.2），境外包裹用。按公开文档写的，没有用真实账号联调过。
 * 鉴权：请求头 17token。请求体和应答都是 JSON，一次可以带多个单号，这里每次只带一个。
 *
 * 17TRACK 是“先登记、后查询”：单号要先 register，它在后台去承运商那里抓取，之后 gettrackinfo 才有结果。
 * 所以第一次查一个单号时：gettrackinfo 被拒（未登记）→ register → 返回“还没有轨迹”并带 pending=true，
 * Common\ExpressTrackService 看到 pending 会缩短缓存时间，几分钟后再查就有了。**按登记的单号数计费**，查询本身不另收费。
 * 承运商：有把握的几家传 17TRACK 的承运商编码，其余不传、由它按单号自动识别（所以“其它”快递也能查）。
 */
class Track17Driver implements ExpressDriver
{
    /** 商城快递编码 → 17TRACK 的承运商编码。没列出的不传，让 17TRACK 自动识别 */
    private const CARRIER = [
        'DHL' => 100001, 'UPS' => 100002, 'FEDEX' => 100003, 'TNT' => 100004, 'USPS' => 21051,
        'CHINAPOST' => 3011, 'EMS' => 3013, 'SF' => 100012,
        'YUNEXPRESS' => 190008, 'YANWEN' => 190012, '4PX' => 190094, 'CAINIAO' => 190271,
    ];

    /** 主状态：已签收 */
    private const SIGNED = ['Delivered'];

    /** 主状态：投递失败、异常、长时间没有更新 */
    private const ABNORMAL = ['DeliveryFailure', 'Exception', 'Expired'];

    /** 单号还没有登记 */
    private const NOT_REGISTERED = -18019902;

    /** 单号已经登记过（重复登记，不算错） */
    private const ALREADY_REGISTERED = -18019901;

    /** 已登记，但暂时还没有抓到轨迹 */
    private const NO_INFO_YET = -18019909;

    public function __construct(private array $config) {}

    public function code(): string
    {
        return 'track17';
    }

    /** 能自动识别承运商，所以什么快递都接 */
    public function supports(string $expressCode): bool
    {
        return true;
    }

    public function query(string $expressCode, string $expressNo, string $phone = ''): array
    {
        $item = ['number' => trim($expressNo)];
        if (isset(self::CARRIER[strtoupper($expressCode)])) {
            $item['carrier'] = self::CARRIER[strtoupper($expressCode)];
        }

        $data = $this->request('gettrackinfo', [$item]);
        $accepted = $data['accepted'][0] ?? null;
        if (is_array($accepted)) {
            return self::parse((array) ($accepted['track_info'] ?? []));
        }

        $code = (int) ($data['rejected'][0]['error']['code'] ?? 0);
        if ($code === self::NO_INFO_YET) {
            return ['status' => 0, 'traces' => [], 'pending' => true];
        }
        if ($code === self::NOT_REGISTERED) {
            $this->register($item);

            return ['status' => 0, 'traces' => [], 'pending' => true];
        }
        $this->fail('查询被拒绝', $data);
    }

    /** 查一次剩余额度：密钥不对会被拒绝 */
    public function ping(): array
    {
        $this->request('getquota', null);

        return ['ok' => true];
    }

    /** 登记单号。已经登记过的不算错 */
    private function register(array $item): void
    {
        $data = $this->request('register', [$item]);
        if (!empty($data['accepted'])) {
            return;
        }
        if ((int) ($data['rejected'][0]['error']['code'] ?? 0) !== self::ALREADY_REGISTERED) {
            $this->fail('登记单号被拒绝', $data);
        }
    }

    /**
     * 把 17TRACK 的 track_info 转成商城的格式。
     * @return array{status:int,traces:array<int,array{time:string,context:string}>}
     */
    public static function parse(array $info): array
    {
        $events = [];
        foreach ((array) ($info['tracking']['providers'] ?? []) as $provider) {
            foreach ((array) ($provider['events'] ?? []) as $e) {
                $text = trim((string) ($e['description'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $location = trim((string) ($e['location'] ?? ''));
                if ($location !== '' && !str_contains($text, $location)) {
                    $text .= '（' . $location . '）';
                }
                $events[] = ['at' => self::time($e), 'context' => mb_substr($text, 0, 500)];
            }
        }
        // 新的在前；没有时间的排最后
        usort($events, fn ($a, $b) => ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0));
        $traces = array_map(fn ($e) => ['time' => $e['at']?->format('Y-m-d H:i:s') ?? '', 'context' => $e['context']], $events);

        $main = (string) ($info['latest_status']['status'] ?? '');
        $status = match (true) {
            in_array($main, self::SIGNED, true) => 2,
            in_array($main, self::ABNORMAL, true) => 3,
            $traces !== [] => 1,
            default => 0,
        };

        return ['status' => $status, 'traces' => $traces];
    }

    /** 事件时间换成应用时区（商城里的时间都是应用时区的，买家端再按他的时区显示） */
    private static function time(array $e): ?Carbon
    {
        foreach (['time_utc', 'time_iso'] as $key) {
            $raw = trim((string) ($e[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            try {
                return Carbon::parse($raw, 'UTC')->setTimezone(config('app.timezone'));
            } catch (\Throwable) {
                // 这个字段的格式认不出来，试下一个
            }
        }

        return null;
    }

    /** @return array<string,mixed> 应答里的 data */
    private function request(string $method, ?array $body): array
    {
        $key = trim((string) ($this->config['api_key'] ?? ''));
        if ($key === '') {
            throw new BusinessException('物流查询还没有配置完整');
        }
        $url = rtrim((string) ($this->config['base_url'] ?? 'https://api.17track.net'), '/') . '/track/v2.2/' . $method;
        try {
            $http = Http::withHeaders(['17token' => $key])->acceptJson()->timeout(max((int) ($this->config['timeout'] ?? 10), 3));
            $res = $body === null ? $http->withBody('', 'application/json')->post($url) : $http->post($url, $body);
        } catch (\Throwable $e) {
            Log::warning('17TRACK 请求失败', ['method' => $method, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            throw new BusinessException('物流查询服务暂时不可用，请稍后再试');
        }
        $json = $res->json();
        if (!is_array($json) || (int) ($json['code'] ?? -1) !== 0) {
            // 密钥不对、IP 不在白名单、额度用完等，都是整个请求被拒
            Log::warning('17TRACK 返回错误', ['method' => $method, 'http' => $res->status(), 'code' => $json['code'] ?? null, 'errors' => mb_substr(json_encode($json['data']['errors'] ?? null, JSON_UNESCAPED_UNICODE), 0, 300)]);
            $msg = (string) ($json['data']['errors'][0]['message'] ?? '');
            throw new BusinessException($method === 'getquota'
                ? '测试失败：' . ($msg !== '' ? mb_substr($msg, 0, 200) : '17TRACK 返回了无法识别的内容（HTTP ' . $res->status() . '）')
                : '物流查询服务暂时不可用，请稍后再试');
        }

        return (array) ($json['data'] ?? []);
    }

    private function fail(string $what, array $data): never
    {
        $error = (array) ($data['rejected'][0]['error'] ?? []);
        Log::warning('17TRACK ' . $what, ['code' => $error['code'] ?? null, 'msg' => mb_substr((string) ($error['message'] ?? ''), 0, 200)]);
        throw new BusinessException('物流查询服务暂时不可用，请稍后再试');
    }
}
