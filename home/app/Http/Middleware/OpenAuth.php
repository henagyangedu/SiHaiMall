<?php

namespace App\Http\Middleware;

use App\Models\OpenApp;
use App\Models\OpenLog;
use App\Services\Common\OpenAppService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 开放接口的身份校验：Authorization: Bearer <密钥>。参数是这个接口要求的权限（不写 = 有密钥就行）。
 * 写操作（非 GET）不论成功失败都记一条调用记录。
 */
class OpenAuth
{
    public function __construct(private OpenAppService $apps) {}

    public function handle(Request $request, Closure $next, string $scope = '')
    {
        [$app, $reason] = $this->apps->authenticate($request->bearerToken(), (string) $request->ip());
        if (!$app) {
            return ApiResponse::fail($reason, 401);
        }
        if ($scope !== '' && !$app->can($scope)) {
            return ApiResponse::fail("这个应用没有 {$scope} 权限", 403);
        }
        $request->attributes->set('open_app', $app);

        $response = $next($request);

        if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            try {
                $this->log($request, $response, $app);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $response;
    }

    private function log(Request $request, $response, OpenApp $app): void
    {
        $code = $response instanceof JsonResponse ? (int) ($response->getData(true)['code'] ?? 1) : $response->getStatusCode();
        OpenLog::query()->create([
            'app_id' => $app->id, 'method' => $request->method(), 'path' => mb_substr($request->path(), 0, 120),
            'ip' => (string) $request->ip(), 'code' => $code,
            'summary' => mb_substr((string) json_encode($request->all(), JSON_UNESCAPED_UNICODE), 0, 500),
            'created_at' => now(),
        ]);
    }
}
