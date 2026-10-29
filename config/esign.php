<?php

return [
    'app_id' => env('ESIGN_APP_ID', ''),
    'app_secret' => env('ESIGN_APP_SECRET', ''),

    // 默认使用沙盒；正式应用设置 ESIGN_SANDBOX=false。
    'sandbox' => env('ESIGN_SANDBOX', true),
    'base_url' => env('ESIGN_BASE_URL'),

    'timeout' => (float) env('ESIGN_TIMEOUT', 30),
    'connect_timeout' => (float) env('ESIGN_CONNECT_TIMEOUT', 10),
    'upload_timeout' => (float) env('ESIGN_UPLOAD_TIMEOUT', 120),

    // 可选回调时间窗口，单位秒；0 表示关闭，避免拒绝平台延迟重试。
    'callback_tolerance' => (int) env('ESIGN_CALLBACK_TOLERANCE', 0),
];
