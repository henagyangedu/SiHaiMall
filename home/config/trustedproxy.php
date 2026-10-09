<?php

/*
 * 可信代理：站点前面有反向代理、负载均衡或 CDN 时，只有来自这些地址的 X-Forwarded-For 才会被当作访客的真实 IP。
 * 不配置的话，后端看到的访客 IP 全是代理的 IP，限流、验证码的按 IP 限制、登录失败锁定都会把所有访客算成同一个人。
 * 由框架自带的 TrustProxies 中间件读取。
 */
$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    // null 不信任任何代理；'*' 信任所有（只在服务器不能被直接访问时用）；或 IP / 网段的数组
    'proxies' => match (true) {
        $proxies === '' => null,
        $proxies === '*' => '*',
        default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
    },
];
