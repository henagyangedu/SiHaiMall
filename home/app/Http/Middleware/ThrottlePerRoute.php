<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * 路由限流（替换框架的 throttle 别名，写法不变：throttle:次数,分钟）。
 * 框架默认按“登录用户，否则按 IP”计数，而且不区分接口；本项目用自己的令牌登录，框架认不出用户，
 * 结果是所有带限流的接口共用同一个 IP 计数器——登录、发验证码、下单、付款的次数会互相挤占。
 * 这里改成按“接口 + IP”各自计数。
 * 不按令牌区分用户：限流发生在校验令牌之前，按令牌计数的话，随便换一个假令牌就能绕过登录、发验证码这些接口的限制。
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();

        // 用路由定义（如 api/orders/{orderNo}/pay）而不是实际地址，换个订单号不会得到新的计数器
        return sha1(implode('|', [$request->method(), $route?->getDomain(), $route?->uri() ?? $request->path(), $request->ip()]));
    }
}
