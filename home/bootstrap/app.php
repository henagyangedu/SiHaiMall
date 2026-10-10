<?php

use App\Support\ApiMessageTranslator;
use App\Support\ApiResponse;
use App\Support\Exceptions\BusinessException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',            // 前缀 /api
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->prefix('admin-api')->group(base_path('routes/admin.php'));
            Route::prefix('notify')->group(base_path('routes/notify.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.auth' => \App\Http\Middleware\AdminAuth::class,
            'api.token'  => \App\Http\Middleware\ApiToken::class,
            'perm'       => \App\Http\Middleware\AdminPerm::class,
            'oplog'      => \App\Http\Middleware\OperationLog::class,
            // 按“接口 + IP”各自计数，见类里的说明
            'throttle'   => \App\Http\Middleware\ThrottlePerRoute::class,
        ]);
        $middleware->appendToGroup('api', \App\Http\Middleware\SetLocale::class);
        // 放在 SetLocale 之后：它的后半段（处理响应）会最后执行，此时语言已经确定
        $middleware->appendToGroup('api', \App\Http\Middleware\TranslateApiMessages::class);
        // 买家接口里的时间按请求头 X-Timezone 换成买家当地的时间
        $middleware->appendToGroup('api', \App\Http\Middleware\LocalizeApiTimes::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $r) => $r->is('api/*', 'admin-api/*', 'notify/*');

        $exceptions->shouldRenderJsonWhen(fn (Request $r, \Throwable $e) => $isApi($r) || $r->expectsJson());

        $exceptions->render(function (BusinessException $e, Request $request) {
            return ApiResponse::fail($e->getMessage(), $e->getCode() ?: 1);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            return ApiResponse::fail($e->validator->errors()->first(), 422, $e->errors());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return ApiResponse::fail('未登录或登录已过期', 401);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return ApiResponse::fail('资源不存在', 404);
            }
        });

        // 接口限流（路由上的 throttle）：和业务错误一样返回 {code,msg}，否则前端拿到的是框架默认的英文报错、弹不出提示。
        // 第三方回调（notify/*）不在此列，保持标准的 HTTP 429 让渠道稍后重试
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*', 'admin-api/*')) {
                $msg = '操作过于频繁，请稍后再试';
                // 限流中间件排在语言识别和提示翻译之前，这里的应答不会经过 TranslateApiMessages，所以自己按请求头翻译
                $lang = (string) ($request->attributes->get('lang') ?: $request->header('X-Lang', ''));
                if ($request->is('api/*') && $lang !== '' && !str_starts_with(strtolower($lang), 'zh')) {
                    $msg = ApiMessageTranslator::translate($msg, $lang);
                }

                return ApiResponse::fail($msg, 429);
            }
        });

        // 生产环境不向前端暴露内部错误细节（开发环境 APP_DEBUG=true 时保留详细报错）
        $exceptions->render(function (\Throwable $e, Request $request) use ($isApi) {
            if (!config('app.debug') && $isApi($request) && !($e instanceof HttpExceptionInterface)) {
                return ApiResponse::fail('服务器繁忙，请稍后再试', 500);
            }
        });
    })->create();
