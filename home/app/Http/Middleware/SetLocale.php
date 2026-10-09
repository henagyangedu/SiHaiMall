<?php

namespace App\Http\Middleware;

use App\Services\Common\LocaleService;
use Closure;
use Illuminate\Http\Request;

/**
 * 语言与币种识别。优先级：请求头（X-Lang / X-Currency）> 会员偏好 > 站点默认。
 * 允许的值取自后台“语言管理 / 币种与汇率”中启用的项（带缓存）。
 */
class SetLocale
{
    public function __construct(private LocaleService $locale) {}

    public function handle(Request $request, Closure $next)
    {
        $lang = $request->header('X-Lang') ?: $request->attributes->get('member_lang');
        if (!in_array($lang, $this->locale->codes(), true)) {
            $lang = $this->locale->defaultLang();
        }

        $currency = $request->header('X-Currency') ?: $request->attributes->get('member_currency');
        if (!in_array($currency, $this->locale->currencyCodes(), true)) {
            $currency = $this->locale->baseCurrency();
        }

        app()->setLocale($lang);
        $request->attributes->set('lang', $lang);
        $request->attributes->set('currency', $currency);

        return $next($request);
    }
}
