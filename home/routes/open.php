<?php

use App\Http\Controllers\Open\FinanceController;
use App\Http\Controllers\Open\GoodsController;
use App\Http\Controllers\Open\OrderController;
use App\Http\Controllers\Open\PingController;
use App\Support\OpenScopes;
use Illuminate\Support\Facades\Route;

/*
 * 开放接口：给 ERP、财务、物流等外部系统用，前缀 /open-api/v1（在 bootstrap/app.php 里注册）。
 * 每个接口都要挂 open.auth:权限（权限登记在 App\Support\OpenScopes；只有 ping 不要求具体权限）。
 * 新增或改动接口时同步改 docs/开放接口.md。
 */

Route::get('ping', PingController::class)->middleware('open.auth');

// 订单
Route::middleware('open.auth:' . OpenScopes::ORDER_READ)->group(function () {
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order_no}', [OrderController::class, 'show']);
    Route::get('express-companies', [OrderController::class, 'expressCompanies']);
});
Route::middleware('open.auth:' . OpenScopes::ORDER_SHIP)->group(function () {
    Route::post('orders/{order_no}/ship', [OrderController::class, 'ship']);
    Route::put('shipments/{id}', [OrderController::class, 'editShipment'])->whereNumber('id');
});

// 规格和库存
Route::get('skus', [GoodsController::class, 'skus'])->middleware('open.auth:' . OpenScopes::GOODS_READ);
Route::post('stock', [GoodsController::class, 'stock'])->middleware('open.auth:' . OpenScopes::STOCK_WRITE);

// 财务
Route::middleware('open.auth:' . OpenScopes::FINANCE_READ)->group(function () {
    Route::get('pay-trades', [FinanceController::class, 'payTrades']);
    Route::get('refunds', [FinanceController::class, 'refunds']);
    Route::get('refunds/{refund_no}', [FinanceController::class, 'refund']);
});
