<?php

use App\Support\ApiResponse;
use App\Support\Exceptions\BusinessException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
        ]);
        $middleware->appendToGroup('api', \App\Http\Middleware\SetLocale::class);
        // 放在 SetLocale 之后：它的后半段（处理响应）会最后执行，此时语言已经确定
        $middleware->appendToGroup('api', \App\Http\Middleware\TranslateApiMessages::class);
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

        // 生产环境不向前端暴露内部错误细节（开发环境 APP_DEBUG=true 时保留详细报错）
        $exceptions->render(function (\Throwable $e, Request $request) use ($isApi) {
            if (!config('app.debug') && $isApi($request) && !($e instanceof HttpExceptionInterface)) {
                return ApiResponse::fail('服务器繁忙，请稍后再试', 500);
            }
        });
    })->create();
