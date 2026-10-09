<?php

namespace App\Http\Middleware;

use App\Services\Api\MemberAuthService;
use App\Services\Common\LocaleService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;

/**
 * C 端登录校验：Authorization: Bearer <token>。
 * 用法：api.token（必须登录）/ api.token:optional（可不登录，登录了则带上会员信息，如商品页按会员币种显示价格）。
 * SetLocale 在路由中间件之前执行，拿不到会员偏好，所以这里补一次：请求头没传 X-Lang / X-Currency 时使用会员偏好。
 */
class ApiToken
{
    public function __construct(private MemberAuthService $auth, private LocaleService $locale) {}

    public function handle(Request $request, Closure $next, string $mode = 'required')
    {
        $plain = $request->bearerToken();
        $member = $plain ? $this->auth->authenticate($plain) : null;

        if (!$member) {
            if ($mode === 'optional') {
                return $next($request);
            }

            return ApiResponse::fail('未登录或登录已过期', 401);
        }

        $request->attributes->set('member', $member);

        if (!$request->header('X-Lang') && in_array($member->lang, $this->locale->codes(), true)) {
            app()->setLocale($member->lang);
            $request->attributes->set('lang', $member->lang);
        }
        if (!$request->header('X-Currency') && in_array($member->currency, $this->locale->currencyCodes(), true)) {
            $request->attributes->set('currency', $member->currency);
        }

        return $next($request);
    }
}
