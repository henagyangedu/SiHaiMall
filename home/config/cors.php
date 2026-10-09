<?php

return [
    'paths' => ['api/*', 'admin-api/*'],
    'allowed_methods' => ['*'],
    // 逗号分隔；生产环境务必改成后台和前台的真实域名
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:3000'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
