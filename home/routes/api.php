<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\DistributionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PayController;
use App\Http\Controllers\Api\RefundController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

// C 端接口（前缀 /api）。H5 / 小程序 / App / PC 共用，来源端用请求头 X-Source 区分
Route::get('ping', fn () => ApiResponse::ok(['time' => now()->toDateTimeString()]));
Route::get('config/init', [ConfigController::class, 'init']);
Route::get('config/cn-divisions', [ConfigController::class, 'cnDivisions']);

// ---- 无需登录（登录后带会员语言/币种偏好）
Route::middleware('api.token:optional')->group(function () {
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('goods', [CatalogController::class, 'goods']);
    Route::get('goods/{id}', [CatalogController::class, 'detail'])->where('id', '[0-9]+');
});

// ---- 注册 / 登录（限流防暴力破解，登录另有失败次数锁定）
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    // 短信验证码：获取（另有按号码、按 IP 的发送限制）、短信登录、短信找回密码
    Route::post('sms-code', [AuthController::class, 'smsCode'])->middleware('throttle:10,1');
    Route::post('login-sms', [AuthController::class, 'loginSms'])->middleware('throttle:20,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
    // 邮箱验证码：获取（另有按邮箱、按 IP 的发送限制）、邮箱找回密码
    Route::post('email-code', [AuthController::class, 'emailCode'])->middleware('throttle:10,1');
    Route::post('reset-password-email', [AuthController::class, 'resetPasswordEmail'])->middleware('throttle:10,1');
});

// ---- 需要登录
Route::middleware('api.token')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('member/profile', [AuthController::class, 'profile']);
    Route::put('member/profile', [AuthController::class, 'updateProfile']);
    Route::put('member/password', [AuthController::class, 'password'])->middleware('throttle:10,1');

    Route::get('addresses', [AddressController::class, 'index']);
    Route::post('addresses', [AddressController::class, 'store']);
    Route::get('addresses/{id}', [AddressController::class, 'show'])->where('id', '[0-9]+');
    Route::put('addresses/{id}', [AddressController::class, 'update'])->where('id', '[0-9]+');
    Route::put('addresses/{id}/default', [AddressController::class, 'setDefault'])->where('id', '[0-9]+');
    Route::delete('addresses/{id}', [AddressController::class, 'destroy'])->where('id', '[0-9]+');

    Route::get('cart', [CartController::class, 'index']);
    Route::post('cart', [CartController::class, 'add']);
    Route::put('cart/{id}', [CartController::class, 'qty'])->where('id', '[0-9]+');
    Route::post('cart/remove', [CartController::class, 'remove']);
    Route::post('cart/clear-invalid', [CartController::class, 'clearInvalid']);

    Route::post('orders/checkout', [OrderController::class, 'checkout']);
    Route::post('orders', [OrderController::class, 'create'])->middleware('throttle:30,1');
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{orderNo}', [OrderController::class, 'show'])->where('orderNo', '[A-Za-z0-9]+');
    Route::post('orders/{orderNo}/cancel', [OrderController::class, 'cancel'])->where('orderNo', '[A-Za-z0-9]+');
    Route::post('orders/{orderNo}/receive', [OrderController::class, 'receive'])->where('orderNo', '[A-Za-z0-9]+');

    // 售后
    Route::get('orders/{orderNo}/refundable', [RefundController::class, 'refundable'])->where('orderNo', '[A-Za-z0-9]+');
    Route::post('orders/{orderNo}/refunds', [RefundController::class, 'apply'])->where('orderNo', '[A-Za-z0-9]+')->middleware('throttle:20,1');
    Route::get('refunds', [RefundController::class, 'index']);
    Route::get('refunds/{refundNo}', [RefundController::class, 'show'])->where('refundNo', '[A-Za-z0-9]+');
    Route::post('refunds/{refundNo}/cancel', [RefundController::class, 'cancel'])->where('refundNo', '[A-Za-z0-9]+');
    Route::post('refunds/{refundNo}/return', [RefundController::class, 'submitReturn'])->where('refundNo', '[A-Za-z0-9]+');

    // 分销中心
    Route::get('distribution/summary', [DistributionController::class, 'summary']);
    Route::post('distribution/apply', [DistributionController::class, 'apply'])->middleware('throttle:10,1');
    Route::get('distribution/commissions', [DistributionController::class, 'commissions']);
    Route::get('distribution/team', [DistributionController::class, 'team']);
    Route::get('distribution/withdraws', [DistributionController::class, 'withdraws']);
    Route::post('distribution/withdraws', [DistributionController::class, 'withdrawApply'])->middleware('throttle:10,1');

    Route::get('orders/{orderNo}/pay/channels', [PayController::class, 'channels'])->where('orderNo', '[A-Za-z0-9]+');
    Route::post('orders/{orderNo}/pay', [PayController::class, 'start'])->where('orderNo', '[A-Za-z0-9]+')->middleware('throttle:20,1');
    Route::get('pay/{tradeNo}/confirm', [PayController::class, 'confirm'])->where('tradeNo', '[A-Za-z0-9]+');
});
