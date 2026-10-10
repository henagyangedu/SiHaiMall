<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\DistributionController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\StockSubscribeController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PayController;
use App\Http\Controllers\Api\PointController;
use App\Http\Controllers\Api\ProtocolController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\UploadController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

// C 端接口（前缀 /api）。H5 / 小程序 / App / PC 共用，来源端用请求头 X-Source 区分
Route::get('ping', fn () => ApiResponse::ok(['time' => now()->toDateTimeString()]));
Route::get('config/init', [ConfigController::class, 'init']);
Route::get('config/cn-divisions', [ConfigController::class, 'cnDivisions']);
// 内容：文章 / 公告 / 帮助中心、广告位（轮播图），无需登录
Route::get('articles', [ContentController::class, 'articles']);
Route::get('articles/{id}', [ContentController::class, 'article'])->where('id', '[0-9]+');
Route::get('article-categories', [ContentController::class, 'categories']);
Route::get('help', [ContentController::class, 'help']);
Route::get('ads', [ContentController::class, 'ads']);
// 协议与政策（用户协议、隐私政策等），无需登录
Route::get('protocols', [ProtocolController::class, 'index']);
Route::get('protocols/{key}', [ProtocolController::class, 'show'])->where('key', '[a-z_]+');

// ---- 无需登录（登录后带会员语言/币种偏好）
Route::middleware('api.token:optional')->group(function () {
    Route::get('categories', [CatalogController::class, 'categories']);
    Route::get('goods', [CatalogController::class, 'goods']);
    Route::get('goods/{id}', [CatalogController::class, 'detail'])->where('id', '[0-9]+');
    // 领券中心：现在能领的优惠券（登录后带上自己领了几张）
    Route::get('coupons', [CouponController::class, 'index']);
    // 商品评价：概况 + 列表
    Route::get('goods/{id}/reviews', [ReviewController::class, 'index'])->where('id', '[0-9]+');
});

// ---- 注册 / 登录（限流防暴力破解，登录另有失败次数锁定）
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    // 短信验证码：获取（另有按号码、按 IP 的发送限制）、短信登录、短信找回密码
    Route::post('sms-code', [AuthController::class, 'smsCode'])->middleware('throttle:10,1');
    Route::post('login-sms', [AuthController::class, 'loginSms'])->middleware('throttle:20,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
    // 微信小程序一键登录（第一次登录自动注册）
    Route::post('login-wechat-mini', [AuthController::class, 'loginWechatMini'])->middleware('throttle:20,1');
    // 邮箱验证码：获取（另有按邮箱、按 IP 的发送限制）、邮箱找回密码
    Route::post('email-code', [AuthController::class, 'emailCode'])->middleware('throttle:10,1');
    Route::post('reset-password-email', [AuthController::class, 'resetPasswordEmail'])->middleware('throttle:10,1');
});

// ---- 需要登录
Route::middleware('api.token')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('member/profile', [AuthController::class, 'profile']);
    Route::put('member/profile', [AuthController::class, 'updateProfile']);
    Route::get('member/points', [PointController::class, 'index']);
    // 优惠券：领取、我的优惠券
    Route::post('coupons/{id}/receive', [CouponController::class, 'receive'])->where('id', '[0-9]+')->middleware('throttle:30,1');
    Route::get('member/coupons', [CouponController::class, 'mine']);
    // 商品收藏
    Route::get('favorites', [FavoriteController::class, 'index']);
    Route::post('favorites/{goodsId}', [FavoriteController::class, 'store'])->where('goodsId', '[0-9]+')->middleware('throttle:60,1');
    Route::delete('favorites/{goodsId}', [FavoriteController::class, 'destroy'])->where('goodsId', '[0-9]+')->middleware('throttle:60,1');
    // 站内信（消息中心）
    Route::get('messages', [MessageController::class, 'index']);
    Route::get('messages/unread-count', [MessageController::class, 'unread']);
    Route::post('messages/read-all', [MessageController::class, 'readAll'])->middleware('throttle:30,1');
    Route::post('messages/{id}/read', [MessageController::class, 'read'])->where('id', '[0-9]+')->middleware('throttle:120,1');
    Route::delete('messages/{id}', [MessageController::class, 'destroy'])->where('id', '[0-9]+')->middleware('throttle:120,1');
    // 到货提醒：缺货的规格可以订阅，补货后通知一次
    Route::get('stock-subscribes', [StockSubscribeController::class, 'index']);
    Route::post('stock-subscribes/{skuId}', [StockSubscribeController::class, 'store'])->where('skuId', '[0-9]+')->middleware('throttle:60,1');
    Route::delete('stock-subscribes/{skuId}', [StockSubscribeController::class, 'destroy'])->where('skuId', '[0-9]+')->middleware('throttle:60,1');
    Route::get('member/signin', [PointController::class, 'signInStatus']);
    Route::post('member/signin', [PointController::class, 'signIn'])->middleware('throttle:10,1');
    Route::put('member/password', [AuthController::class, 'password'])->middleware('throttle:10,1');
    // 绑定邮箱或手机号（验证码用 auth/email-code、auth/sms-code 以 register 用途获取）
    Route::post('member/bind', [AuthController::class, 'bind'])->middleware('throttle:10,1');

    // 买家上传图片（售后凭证、头像），另有每人每天的张数上限
    Route::post('upload/image', [UploadController::class, 'image'])->middleware('throttle:30,1');

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
    // 物流轨迹（每个包裹一条；结果有缓存，不会每次都向服务商查询）
    Route::get('orders/{orderNo}/tracks', [OrderController::class, 'tracks'])->where('orderNo', '[A-Za-z0-9]+')->middleware('throttle:30,1');

    // 售后
    Route::get('orders/{orderNo}/refundable', [RefundController::class, 'refundable'])->where('orderNo', '[A-Za-z0-9]+');
    Route::post('orders/{orderNo}/refunds', [RefundController::class, 'apply'])->where('orderNo', '[A-Za-z0-9]+')->middleware('throttle:20,1');
    // 发票：查看（能不能申请、开票金额、现在的状态）、申请或修改、撤销
    Route::get('orders/{orderNo}/invoice', [InvoiceController::class, 'show'])->where('orderNo', '[A-Za-z0-9]+');
    Route::post('orders/{orderNo}/invoice', [InvoiceController::class, 'store'])->where('orderNo', '[A-Za-z0-9]+')->middleware('throttle:20,1');
    Route::delete('orders/{orderNo}/invoice', [InvoiceController::class, 'destroy'])->where('orderNo', '[A-Za-z0-9]+');
    // 评价订单里的一件商品（订单完成后）
    Route::post('orders/{orderNo}/reviews', [ReviewController::class, 'store'])->where('orderNo', '[A-Za-z0-9]+')->middleware('throttle:20,1');
    // 追评（评价过的商品再追加一次）
    Route::post('orders/{orderNo}/reviews/append', [ReviewController::class, 'append'])->where('orderNo', '[A-Za-z0-9]+')->middleware('throttle:20,1');
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
    Route::get('pay/wechat/oauth-url', [PayController::class, 'wechatOauthUrl'])->middleware('throttle:30,1');
    Route::get('pay/{tradeNo}/confirm', [PayController::class, 'confirm'])->where('tradeNo', '[A-Za-z0-9]+');
});
