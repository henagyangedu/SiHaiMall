<?php

return [
    'token_ttl_hours' => (int) env('ADMIN_TOKEN_TTL_HOURS', 12),   // 令牌有效期（每次使用会顺延）
    'max_login_fail'  => (int) env('ADMIN_MAX_LOGIN_FAIL', 5),     // 连续失败次数上限
    'lock_minutes'    => (int) env('ADMIN_LOCK_MINUTES', 15),      // 锁定时长
    'init_password'   => env('ADMIN_INIT_PASSWORD'),               // 初始管理员密码，留空则随机生成
];
