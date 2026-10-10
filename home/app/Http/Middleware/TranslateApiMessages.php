<?php

namespace App\Http\Middleware;

use App\Support\ApiMessageTranslator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * C 端接口的提示多语言：业务代码里的提示都是中文，买家的语言不是中文时，在响应返回前翻译成英文。
 * 处理四处买家会看到的文字：顶层的 msg、结算预览的 data.problems[].msg 和 data.coupons[].reason、可退信息的 data.reason。
 * 只作用于 /api/*，后台管理端不翻译。
 */
class TranslateApiMessages
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // 语言在这里才取：登录后的请求没带 X-Lang 时，ApiToken 中间件会把它换成会员的语言偏好
        $lang = (string) $request->attributes->get('lang', '');
        if (!$request->is('api/*') || !$response instanceof JsonResponse || $lang === '' || str_starts_with(strtolower($lang), 'zh')) {
            return $response;
        }

        $body = $response->getData(true);
        if (!is_array($body)) {
            return $response;
        }
        if (isset($body['msg']) && is_string($body['msg'])) {
            $body['msg'] = ApiMessageTranslator::translate($body['msg'], $lang);
        }
        if (is_array($body['data'] ?? null)) {
            if (isset($body['data']['reason']) && is_string($body['data']['reason'])) {
                $body['data']['reason'] = ApiMessageTranslator::translate($body['data']['reason'], $lang);
            }
            if (is_array($body['data']['problems'] ?? null)) {
                foreach ($body['data']['problems'] as $i => $p) {
                    if (is_array($p) && is_string($p['msg'] ?? null)) {
                        $body['data']['problems'][$i]['msg'] = ApiMessageTranslator::translate($p['msg'], $lang);
                    }
                }
            }
            // 结算预览里每张券“为什么不能用”
            if (is_array($body['data']['coupons'] ?? null)) {
                foreach ($body['data']['coupons'] as $i => $c) {
                    if (is_array($c) && is_string($c['reason'] ?? null) && $c['reason'] !== '') {
                        $body['data']['coupons'][$i]['reason'] = ApiMessageTranslator::translate($c['reason'], $lang);
                    }
                }
            }
        }

        return $response->setData($body);
    }
}
