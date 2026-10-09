<?php

use App\Http\Controllers\Notify\PayNotifyController;
use Illuminate\Support\Facades\Route;

// 第三方回调（前缀 /notify 由 bootstrap/app.php 加）。不走登录，处理里验签；限流放宽以免渠道集中重试被误拦
Route::post('pay/{channel}', [PayNotifyController::class, 'handle'])
    ->where('channel', '[a-z0-9_]+')
    ->middleware('throttle:600,1');
