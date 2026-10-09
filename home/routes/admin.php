<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ConfigController;
use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Admin\DriverController;
use App\Http\Controllers\Admin\GoodsController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\MemberLevelController;
use App\Http\Controllers\Admin\MemberPointController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\OptionController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderNotifyController;
use App\Http\Controllers\Admin\OrderTradeController;
use App\Http\Controllers\Admin\OrderShipController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\StockLogController;
use App\Http\Controllers\Admin\TranslateController;
use App\Http\Controllers\Admin\UploadController;
use Illuminate\Support\Facades\Route;

// 后台接口，前缀 /admin-api（见 bootstrap/app.php）
Route::pattern('id', '[0-9]+');
Route::pattern('driver', '[a-z0-9_]+');

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');

Route::middleware(['admin.auth', 'oplog'])->group(function () {
    // 登录态
    Route::get('auth/info', [AuthController::class, 'info']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/password', [AuthController::class, 'password']);

    // 管理员
    Route::prefix('admin')->group(function () {
        Route::get('list', [AdminController::class, 'index'])->middleware('perm:setting:admin:view');
        Route::post('/', [AdminController::class, 'store'])->middleware('perm:setting:admin:add');
        Route::put('{id}', [AdminController::class, 'update'])->middleware('perm:setting:admin:edit');
        Route::delete('{id}', [AdminController::class, 'destroy'])->middleware('perm:setting:admin:del');
        Route::post('{id}/reset', [AdminController::class, 'reset'])->middleware('perm:setting:admin:reset');
    });

    // 角色
    Route::prefix('role')->group(function () {
        Route::get('list', [RoleController::class, 'index'])->middleware('perm:setting:role:view');
        Route::get('options', [RoleController::class, 'options'])->middleware('perm:setting:role:view,setting:admin:view');
        Route::get('{id}', [RoleController::class, 'show'])->middleware('perm:setting:role:view');
        Route::post('/', [RoleController::class, 'store'])->middleware('perm:setting:role:add');
        Route::put('{id}', [RoleController::class, 'update'])->middleware('perm:setting:role:edit');
        Route::delete('{id}', [RoleController::class, 'destroy'])->middleware('perm:setting:role:del');
    });

    // 菜单规则（增删改仅超级管理员）
    Route::prefix('menu')->group(function () {
        Route::get('tree', [MenuController::class, 'tree'])->middleware('perm:setting:menu:view,setting:role:view');
        Route::post('/', [MenuController::class, 'store'])->middleware('perm:super');
        Route::put('{id}', [MenuController::class, 'update'])->middleware('perm:super');
        Route::delete('{id}', [MenuController::class, 'destroy'])->middleware('perm:super');
    });

    // 操作日志
    Route::get('log/list', [LogController::class, 'index'])->middleware('perm:setting:log:view');

    // 语言管理
    Route::prefix('language')->group(function () {
        Route::get('list', [LanguageController::class, 'index'])->middleware('perm:setting:language:view');
        // 各多语言表单的页签数据：拥有任一相关页面权限即可读取（新增多语言页面时把它的 view 权限加到这里）
        Route::get('options', [LanguageController::class, 'options'])
            ->middleware('perm:setting:language:view,setting:base:view,setting:message:view,setting:protocol:view,setting:receipt:view,goods:category:view,goods:brand:view,goods:list:view,member:level:view,member:list:view,setting:api_translate:view');
        Route::post('/', [LanguageController::class, 'store'])->middleware('perm:setting:language:add');
        Route::put('{id}', [LanguageController::class, 'update'])->middleware('perm:setting:language:edit');
        Route::post('{id}/default', [LanguageController::class, 'setDefault'])->middleware('perm:setting:language:edit');
        Route::delete('{id}', [LanguageController::class, 'destroy'])->middleware('perm:setting:language:del');
    });

    // 币种与汇率
    Route::prefix('currency')->group(function () {
        Route::get('list', [CurrencyController::class, 'index'])->middleware('perm:setting:currency:view');
        Route::post('/', [CurrencyController::class, 'store'])->middleware('perm:setting:currency:add');
        Route::put('{id}', [CurrencyController::class, 'update'])->middleware('perm:setting:currency:edit');
        Route::put('{id}/rate', [CurrencyController::class, 'rate'])->middleware('perm:setting:currency:rate');
        Route::post('{id}/base', [CurrencyController::class, 'setBase'])->middleware('perm:super'); // 影响全部价格口径，仅超管
        Route::delete('{id}', [CurrencyController::class, 'destroy'])->middleware('perm:setting:currency:del');
    });

    // 通用下拉选项（只读）：启用的币种
    Route::get('options/currencies', [OptionController::class, 'currencies'])
        ->middleware('perm:goods:list:view,setting:currency:view,member:list:edit');

    // 系统设置：基础信息 / 交易设置（分组由 defaults 指定）
    Route::get('config/base', [ConfigController::class, 'show'])->defaults('group', 'base')->middleware('perm:setting:base:view');
    Route::put('config/base', [ConfigController::class, 'save'])->defaults('group', 'base')->middleware('perm:setting:base:edit');
    Route::get('config/trade', [ConfigController::class, 'show'])->defaults('group', 'trade')->middleware('perm:setting:trade:view');
    Route::put('config/trade', [ConfigController::class, 'save'])->defaults('group', 'trade')->middleware('perm:setting:trade:edit');

    // 接口配置：系统存储（短信、支付、物流查询后续按同样方式加）
    Route::get('driver/storage', [DriverController::class, 'index'])->defaults('type', 'storage')->middleware('perm:setting:api_storage:view');
    Route::put('driver/storage/{driver}', [DriverController::class, 'save'])->defaults('type', 'storage')->middleware('perm:setting:api_storage:edit');
    Route::post('driver/storage/{driver}/test', [DriverController::class, 'test'])->defaults('type', 'storage')->middleware('perm:setting:api_storage:test');

    // 接口配置：商城支付渠道（Stripe / PayPal；支付宝、微信下一步接入）
    Route::get('driver/pay', [DriverController::class, 'index'])->defaults('type', 'pay')->middleware('perm:setting:api_pay:view');
    Route::put('driver/pay/{driver}', [DriverController::class, 'save'])->defaults('type', 'pay')->middleware('perm:setting:api_pay:edit');
    Route::post('driver/pay/{driver}/test', [DriverController::class, 'test'])->defaults('type', 'pay')->middleware('perm:setting:api_pay:test');

    // 接口配置：AI 翻译模型
    Route::get('driver/translate', [DriverController::class, 'index'])->defaults('type', 'translate')->middleware('perm:setting:api_translate:view');
    Route::put('driver/translate/{driver}', [DriverController::class, 'save'])->defaults('type', 'translate')->middleware('perm:setting:api_translate:edit');
    Route::post('driver/translate/{driver}/test', [DriverController::class, 'test'])->defaults('type', 'translate')->middleware('perm:setting:api_translate:test');

    // AI 翻译：翻译接口（有任一商品、分类、品牌编辑权限即可用）、路由与额度设置、术语表、用量日志
    Route::prefix('translate')->group(function () {
        Route::post('/', [TranslateController::class, 'translate'])
            ->middleware(['throttle:30,1', 'perm:goods:list:add,goods:list:edit,goods:category:add,goods:category:edit,goods:brand:add,goods:brand:edit,setting:base:edit,setting:api_translate:test']);
        Route::get('settings', [TranslateController::class, 'settings'])->middleware('perm:setting:api_translate:view');
        Route::put('settings', [TranslateController::class, 'saveSettings'])->middleware('perm:setting:api_translate:edit');
        Route::get('glossary', [TranslateController::class, 'glossaryIndex'])->middleware('perm:setting:api_translate:view');
        Route::post('glossary', [TranslateController::class, 'glossaryStore'])->middleware('perm:setting:api_translate:edit');
        Route::put('glossary/{id}', [TranslateController::class, 'glossaryUpdate'])->middleware('perm:setting:api_translate:edit');
        Route::delete('glossary/{id}', [TranslateController::class, 'glossaryDestroy'])->middleware('perm:setting:api_translate:edit');
        Route::get('log', [TranslateController::class, 'logIndex'])->middleware('perm:setting:api_translate:view');
    });

    // 文件上传：登录即可，限流防滥用
    Route::post('upload/image', [UploadController::class, 'image'])->middleware('throttle:60,1');

    // 商品：分类、品牌、商品、库存流水
    Route::prefix('goods')->group(function () {
        Route::prefix('category')->group(function () {
            Route::get('tree', [CategoryController::class, 'tree'])->middleware('perm:goods:category:view,goods:list:view');
            Route::post('/', [CategoryController::class, 'store'])->middleware('perm:goods:category:add');
            Route::put('{id}', [CategoryController::class, 'update'])->middleware('perm:goods:category:edit');
            Route::post('{id}/status', [CategoryController::class, 'status'])->middleware('perm:goods:category:edit');
            Route::delete('{id}', [CategoryController::class, 'destroy'])->middleware('perm:goods:category:del');
        });
        Route::prefix('brand')->group(function () {
            Route::get('list', [BrandController::class, 'index'])->middleware('perm:goods:brand:view');
            Route::get('options', [BrandController::class, 'options'])->middleware('perm:goods:brand:view,goods:list:view');
            Route::post('/', [BrandController::class, 'store'])->middleware('perm:goods:brand:add');
            Route::put('{id}', [BrandController::class, 'update'])->middleware('perm:goods:brand:edit');
            Route::post('{id}/status', [BrandController::class, 'status'])->middleware('perm:goods:brand:edit');
            Route::delete('{id}', [BrandController::class, 'destroy'])->middleware('perm:goods:brand:del');
        });

        Route::get('stocklog/list', [StockLogController::class, 'index'])->middleware('perm:goods:stocklog:view');

        // 商品本身（放在分类、品牌之后；{id} 只匹配数字，不会和上面的路径冲突）
        Route::get('list', [GoodsController::class, 'index'])->middleware('perm:goods:list:view');
        Route::get('{id}', [GoodsController::class, 'show'])->middleware('perm:goods:list:view');
        Route::post('/', [GoodsController::class, 'store'])->middleware('perm:goods:list:add');
        Route::put('{id}', [GoodsController::class, 'update'])->middleware('perm:goods:list:edit');
        Route::post('{id}/sale', [GoodsController::class, 'sale'])->middleware('perm:goods:list:sale');
        Route::post('{id}/stock', [GoodsController::class, 'stock'])->middleware('perm:goods:list:stock');
        Route::delete('{id}', [GoodsController::class, 'destroy'])->middleware('perm:goods:list:del');
    });

    // 会员：列表与详情、等级、积分记录（敏感操作各自独立权限点：改资料、禁用、调余额、调积分、导出）
    Route::prefix('member')->group(function () {
        Route::get('list', [MemberController::class, 'index'])->middleware('perm:member:list:view');
        Route::get('list/export', [MemberController::class, 'export'])->middleware(['throttle:10,1', 'perm:member:list:export']);
        Route::get('list/{id}', [MemberController::class, 'show'])->middleware('perm:member:list:detail');
        Route::put('list/{id}', [MemberController::class, 'update'])->middleware('perm:member:list:edit');
        Route::post('list/{id}/status', [MemberController::class, 'status'])->middleware('perm:member:list:status');
        Route::post('list/{id}/password', [MemberController::class, 'password'])->middleware('perm:member:list:edit');
        Route::post('list/{id}/points', [MemberController::class, 'points'])->middleware('perm:member:list:points');
        Route::post('list/{id}/balance', [MemberController::class, 'balance'])->middleware('perm:member:list:balance');
        Route::get('list/{id}/balance-log', [MemberController::class, 'balanceLog'])->middleware('perm:member:list:detail');

        Route::get('level/list', [MemberLevelController::class, 'index'])->middleware('perm:member:level:view');
        Route::get('level/options', [MemberLevelController::class, 'options'])->middleware('perm:member:level:view,member:list:view');
        Route::post('level', [MemberLevelController::class, 'store'])->middleware('perm:member:level:add');
        Route::put('level/{id}', [MemberLevelController::class, 'update'])->middleware('perm:member:level:edit');
        Route::delete('level/{id}', [MemberLevelController::class, 'destroy'])->middleware('perm:member:level:del');

        Route::get('points', [MemberPointController::class, 'index'])->middleware('perm:member:points:view,member:list:detail');
    });

    // 订单：列表/详情/备注/改价/取消；发货管理（分批发货、改单号）；确认收货算发货环节的权限
    Route::prefix('order')->group(function () {
        Route::get('list', [OrderController::class, 'index'])->middleware('perm:order:list:view');
        Route::get('counts', [OrderController::class, 'counts'])->middleware('perm:order:list:view');
        Route::get('list/{id}', [OrderController::class, 'show'])->middleware('perm:order:list:detail,order:ship:view');
        Route::post('list/{id}/remark', [OrderController::class, 'remark'])->middleware('perm:order:list:remark');
        Route::post('list/{id}/price', [OrderController::class, 'price'])->middleware('perm:order:list:price');
        Route::post('list/{id}/cancel', [OrderController::class, 'cancel'])->middleware('perm:order:list:cancel');
        Route::post('list/{id}/receive', [OrderController::class, 'receive'])->middleware('perm:order:ship:edit');

        Route::get('trade/list', [OrderTradeController::class, 'index'])->middleware('perm:order:trade:view');
        Route::get('notify/list', [OrderNotifyController::class, 'index'])->middleware('perm:order:notify:view');
        Route::get('notify/{id}', [OrderNotifyController::class, 'show'])->middleware('perm:order:notify:view');
        Route::post('notify/{id}/retry', [OrderNotifyController::class, 'retry'])->middleware(['throttle:30,1', 'perm:order:notify:retry']);

        Route::get('ship/list', [OrderShipController::class, 'index'])->middleware('perm:order:ship:view');
        Route::get('ship/express', [OrderShipController::class, 'express'])->middleware('perm:order:ship:view,order:ship:do,order:ship:edit');
        Route::post('ship/{id}', [OrderShipController::class, 'ship'])->middleware('perm:order:ship:do');
        Route::put('ship/record/{shippingId}', [OrderShipController::class, 'editExpress'])->middleware('perm:order:ship:edit');
    });
});
