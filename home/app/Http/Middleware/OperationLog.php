<?php

namespace App\Http\Middleware;

use App\Services\Admin\LogService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 后台操作日志：只记录写操作（非 GET），敏感字段脱敏；日志写入失败不影响业务。 */
class OperationLog
{
    private const SENSITIVE = ['password', 'pwd', 'token', 'secret', 'key', 'config', 'sign'];

    public function __construct(private LogService $logs) {}

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $response;
        }

        try {
            $this->write($request, $response);
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }

    private function write(Request $request, $response): void
    {
        $perm = '';
        foreach ((array) $request->route()?->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'perm:')) {
                $perm = substr($m, 5);
                break;
            }
        }

        $ok = $response instanceof JsonResponse
            ? ((int) ($response->getData(true)['code'] ?? 1) === 0)
            : $response->getStatusCode() < 400;

        $params = json_encode($this->mask($request->except(['_token'])), JSON_UNESCAPED_UNICODE) ?: '';

        $this->logs->record([
            'admin_id'   => $request->attributes->get('admin')?->id ?? 0,
            'module'     => (string) $request->segment(2),
            'action'     => $perm !== '' ? $perm : $request->method() . ' ' . $request->path(),
            'method'     => $request->method(),
            'url'        => Str::limit($request->getRequestUri(), 250, ''),
            'params'     => mb_substr($params, 0, 5000),
            'ip'         => (string) $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'status'     => $ok ? 1 : 0,
        ]);
    }

    private function mask(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k)) {
                foreach (self::SENSITIVE as $s) {
                    if (stripos($k, $s) !== false) {
                        $data[$k] = '***';
                        continue 2;
                    }
                }
            }
            if (is_array($v)) {
                $data[$k] = $this->mask($v);
            }
        }

        return $data;
    }
}
