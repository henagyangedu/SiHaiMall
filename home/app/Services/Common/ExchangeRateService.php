<?php

namespace App\Services\Common;

use App\Models\Currency;
use App\Support\Exceptions\BusinessException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 汇率自动更新。
 * - 哪些币种自动更新：gm_currency.rate_mode = 2 的（基准币种除外）。一个都没有时不会去请求行情。
 * - 行情源（都是免费、不用密钥的公开接口，每天更新一次）：见 SOURCES。
 * - 写进币种表的汇率 = 行情汇率 ×（1 + 加价百分比），保留 6 位小数。加价是为了盖住收款渠道的换汇手续费。
 * - 保护：和现在的汇率相比变动超过“单次最大变动”的不更新（行情源出错或币种代码对不上时不至于把价格改乱），
 *   这种情况要管理员手工改一次汇率，之后自动更新再接着走。
 * - 行情源连不上、返回的内容不对：所有汇率保持不变，只记下错误。
 * 设置在 gm_config 的 currency 分组；最近一次更新的结果也存在那里（rate_sync_state）。
 * 已下的订单固化了下单时的汇率，不受影响。
 */
class ExchangeRateService
{
    /** 行情源：名称和说明给后台看 */
    public const SOURCES = [
        'frankfurter' => ['name' => 'Frankfurter（欧洲央行参考汇率）', 'desc' => '约 30 种主要货币，工作日更新'],
        'erapi'       => ['name' => 'ExchangeRate-API（公开接口）', 'desc' => '160 多种货币，每天更新'],
    ];

    public function __construct(private ConfigService $config, private LocaleService $locale) {}

    /** 后台用：设置、行情源列表、最近一次更新的结果 */
    public function settings(): array
    {
        $c = $this->config->get('currency');
        $sources = [];
        foreach (self::SOURCES as $code => $s) {
            $sources[] = ['code' => $code] + $s;
        }

        return [
            'rate_source'     => $c['rate_source'],
            'rate_markup'     => self::trim((string) $c['rate_markup']),
            'rate_max_change' => (int) $c['rate_max_change'],
            'sources'         => $sources,
            'state'           => $this->state(),
        ];
    }

    public function saveSettings(array $d): void
    {
        $this->config->save('currency', array_intersect_key($d, array_flip(['rate_source', 'rate_markup', 'rate_max_change'])));
    }

    /** 最近一次更新的结果；还没更新过是 null */
    public function state(): ?array
    {
        $s = json_decode((string) $this->config->get('currency')['rate_sync_state'], true);

        return is_array($s) && isset($s['at']) ? $s : null;
    }

    /**
     * 更新一次。$strict 为真时（后台点“立即更新”）行情源出错直接抛给管理员看；定时任务里只记日志。
     * @return array{ok:bool,error:string,updated:int,items:array<int,array<string,mixed>>}
     */
    public function sync(bool $strict = false): array
    {
        $base = Currency::query()->where('is_base', 1)->first();
        $targets = Currency::query()->where('rate_mode', 2)->where('is_base', 0)->orderBy('sort')->orderBy('id')->get();
        if (!$base || $targets->isEmpty()) {
            if ($strict) {
                throw new BusinessException('没有设为自动更新的币种');
            }

            return ['ok' => true, 'error' => '', 'updated' => 0, 'items' => []];
        }

        $cfg = $this->config->get('currency');
        $source = (string) $cfg['rate_source'];
        try {
            [$market, $date] = $this->fetch($source, (string) $base->code, $targets->pluck('code')->all());
        } catch (\Throwable $e) {
            $error = $e instanceof BusinessException ? $e->getMessage() : '行情源请求失败';
            Log::warning('汇率自动更新失败', ['source' => $source, 'error' => $e->getMessage()]);
            // 上一次成功的明细留着，只记下这次没成功
            $this->saveState(['at' => now()->toDateTimeString(), 'source' => $source, 'ok' => false, 'error' => $error] + array_intersect_key($this->state() ?? [], ['date' => 1, 'items' => 1]));
            if ($strict) {
                throw new BusinessException($error . '，汇率没有变动');
            }

            return ['ok' => false, 'error' => $error, 'updated' => 0, 'items' => []];
        }

        $factor = bcadd('1', bcdiv((string) $cfg['rate_markup'], '100', 8), 8);
        $limit = bcdiv((string) $cfg['rate_max_change'], '100', 8);
        $items = [];
        $updated = 0;
        foreach ($targets as $cur) {
            $old = (string) $cur->rate;
            $item = ['code' => (string) $cur->code, 'market' => '', 'old' => self::trim($old), 'rate' => self::trim($old), 'status' => 'skipped', 'msg' => ''];
            $m = $market[(string) $cur->code] ?? null;
            if ($m === null || bccomp($m, '0', 8) <= 0) {
                $item['msg'] = '这个行情源没有该币种的汇率';
                $items[] = $item;
                continue;
            }
            $new = self::round6(bcmul($m, $factor, 12));
            $item['market'] = self::trim($m);
            if (bccomp($new, '0', 6) <= 0) {
                $item['msg'] = '汇率太小，无法表示';
            } elseif (bccomp($old, '0', 8) > 0 && bccomp(self::abs(bcdiv(bcsub($new, $old, 8), $old, 8)), $limit, 8) > 0) {
                $item['msg'] = '和现在的汇率相差超过 ' . (int) $cfg['rate_max_change'] . '%，没有更新（确认无误的话请手工改一次汇率）';
                $item['rate_new'] = self::trim($new);
            } elseif (bccomp($new, $old, 8) === 0) {
                $item['status'] = 'unchanged';
            } else {
                $cur->update(['rate' => $new]);
                $item['status'] = 'updated';
                $item['rate'] = self::trim($new);
                $updated++;
            }
            $items[] = $item;
        }
        if ($updated > 0) {
            $this->locale->flush();
        }
        $this->saveState(['at' => now()->toDateTimeString(), 'source' => $source, 'date' => $date, 'ok' => true, 'error' => '', 'items' => $items]);

        return ['ok' => true, 'error' => '', 'updated' => $updated, 'items' => $items];
    }

    /**
     * 向行情源取“1 个基准币种 = 多少外币”。
     * @param string[] $codes
     * @return array{0:array<string,string>,1:string} [币种 => 汇率, 行情日期]
     */
    private function fetch(string $source, string $base, array $codes): array
    {
        $http = Http::timeout(10)->connectTimeout(5)->acceptJson();
        if ($source === 'erapi') {
            $res = $http->get('https://open.er-api.com/v6/latest/' . $base);
            $body = $res->json();
            if (!$res->successful() || !is_array($body) || ($body['result'] ?? '') !== 'success' || !is_array($body['rates'] ?? null)) {
                throw new BusinessException($this->sourceError($res->status(), is_array($body) ? (string) ($body['error-type'] ?? '') : ''));
            }
            $date = isset($body['time_last_update_unix']) ? date('Y-m-d', (int) $body['time_last_update_unix']) : '';
        } else {
            $res = $http->get('https://api.frankfurter.dev/v1/latest', ['base' => $base, 'symbols' => implode(',', $codes)]);
            $body = $res->json();
            if (!$res->successful() || !is_array($body) || !is_array($body['rates'] ?? null)) {
                throw new BusinessException($this->sourceError($res->status(), is_array($body) ? (string) ($body['message'] ?? '') : ''));
            }
            $date = (string) ($body['date'] ?? '');
        }

        $out = [];
        foreach ($codes as $code) {
            $v = $body['rates'][$code] ?? null;
            if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
                $out[$code] = sprintf('%.8F', (float) $v);
            }
        }

        return [$out, $date];
    }

    private function sourceError(int $status, string $detail): string
    {
        // 基准币种或请求的币种它都不认识时，Frankfurter 回 404、ExchangeRate-API 回 unsupported-code
        if ($status === 404 || $status === 422 || $detail === 'unsupported-code') {
            return '行情源不支持当前的基准币种或要更新的币种，请换一个行情源';
        }

        return '行情源暂时取不到汇率' . ($status >= 400 ? "（HTTP {$status}）" : '');
    }

    private function saveState(array $state): void
    {
        $this->config->save('currency', ['rate_sync_state' => json_encode($state, JSON_UNESCAPED_UNICODE)]);
    }

    /** 四舍五入到 6 位小数 */
    private static function round6(string $v): string
    {
        return bcadd($v, '0.0000005', 6);
    }

    private static function abs(string $v): string
    {
        return ltrim($v, '-');
    }

    /** 0.14943000 -> 0.14943 */
    private static function trim(string $v): string
    {
        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }
}
