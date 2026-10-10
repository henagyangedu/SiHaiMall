<?php

namespace App\Http\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * C 端接口的时间按买家的时区显示。
 * 数据库和业务代码里的时间都是应用时区（北京时间）的“年-月-日 时:分:秒”；买家端在请求头 X-Timezone 里带上自己的时区
 * （IANA 名称如 America/New_York，或 +08:00 这样的偏移），这里在响应返回前把 data 里所有这种格式的时间换算过去，格式不变。
 * - 只作用于 /api/*；后台管理端始终是应用时区。
 * - 没带请求头、时区认不出来、和应用时区相同：原样返回。
 * - 只换“年-月-日 时:分:秒”的完整时间；只有日期的（生日等）不动。
 * - 只影响显示：业务上的“一天”（每日签到、数据统计）和有效期的判断仍然按应用时区，在服务端算。
 * - 通知的标题和正文是发送时生成的文字，里面的时间不换算。
 */
class LocalizeApiTimes
{
    private const PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (!$request->is('api/*') || !$response instanceof JsonResponse) {
            return $response;
        }
        $from = new DateTimeZone((string) config('app.timezone'));
        $to = self::zone((string) $request->header('X-Timezone', ''));
        if ($to === null) {
            return $response;
        }
        if ($to->getName() === $from->getName()) {
            return $response;
        }

        $body = $response->getData(true);
        if (!is_array($body) || !isset($body['data']) || !is_array($body['data'])) {
            return $response;
        }
        array_walk_recursive($body['data'], function (&$v) use ($from, $to) {
            if (is_string($v) && strlen($v) === 19 && preg_match(self::PATTERN, $v)) {
                $t = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v, $from);
                if ($t !== false) {
                    $v = $t->setTimezone($to)->format('Y-m-d H:i:s');
                }
            }
        });

        return $response->setData($body);
    }

    /** 请求头里的时区；认不出来返回 null */
    private static function zone(string $name): ?DateTimeZone
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 64 || !preg_match('/^(?:[A-Za-z][A-Za-z0-9_+\-]*(?:\/[A-Za-z0-9_+\-]+){0,2}|[+-]\d{2}:\d{2})$/', $name)) {
            return null;
        }
        try {
            return new DateTimeZone($name);
        } catch (\Throwable) {
            return null;
        }
    }
}
