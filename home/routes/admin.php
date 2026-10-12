<?php

use App\Http\Controllers\Admin\AdController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ArticleController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\BundleController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ConfigController;
use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Admin\DistributionController;
use App\Http\Controllers\Admin\DriverController;
use App\Http\Controllers\Admin\GoodsController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\MemberLevelController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\GoodsReviewController;
use App\Http\Controllers\Admin\MemberPointController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\MessageBroadcastController;
use App\Http\Controllers\Admin\OrderInvoiceController;
use App\Http\Controllers\Admin\MessageTemplateController;
use App\Http\Controllers\Admin\OptionController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderNotifyController;
use App\Http\Controllers\Admin\OrderRefundController;
use App\Http\Controllers\Admin\OrderTradeController;
use App\Http\Controllers\Admin\OrderShipController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SaleRegionController;
use App\Http\Controllers\Admin\ShippingTemplateController;
use App\Http\Controllers\Admin\StatsController;
use App\Http\Controllers\Admin\StockLogController;
use App\Http\Controllers\Admin\OpenAppController;
use App\Http\Controllers\Admin\TaxRateController;
use App\Http\Controllers\Admin\TranslateController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\Admin\WaybillController;
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
            ->middleware('perm:setting:language:view,setting:base:view,setting:message:view,setting:protocol:view,setting:receipt:view,goods:category:view,goods:brand:view,goods:list:view,member:level:view,member:list:view,member:broadcast:view,setting:api_translate:view,content:article:view,content:notice:view,content:help:view,content:ad:view');
        Route::post('/', [LanguageController::class, 'store'])->middleware('perm:setting:language:add');
        Route::put('{id}', [LanguageController::class, 'update'])->middleware('perm:setting:language:edit');
        Route::post('{id}/default', [LanguageController::class, 'setDefault'])->middleware('perm:setting:language:edit');
        Route::delete('{id}', [LanguageController::class, 'destroy'])->middleware('perm:setting:language:del');
    });

    // 币种与汇率
    Route::prefix('currency')->group(function () {
        Route::get('list', [CurrencyController::class, 'index'])->middleware('perm:setting:currency:view');
        Route::post('/', [CurrencyController::class, 'store'])->middleware('perm:setting:currency:add');
        // 汇率自动更新：设置、立即更新、单个币种的更新方式
        Route::get('rate-config', [CurrencyController::class, 'rateConfig'])->middleware('perm:setting:currency:view');
        Route::put('rate-config', [CurrencyController::class, 'saveRateConfig'])->middleware('perm:setting:currency:rate');
        Route::post('rate-sync', [CurrencyController::class, 'rateSync'])->middleware('perm:setting:currency:rate');
        Route::put('{id}/rate-mode', [CurrencyController::class, 'rateMode'])->whereNumber('id')->middleware('perm:setting:currency:rate');
        Route::put('{id}', [CurrencyController::class, 'update'])->whereNumber('id')->middleware('perm:setting:currency:edit');
        Route::put('{id}/rate', [CurrencyController::class, 'rate'])->middleware('perm:setting:currency:rate');
        Route::post('{id}/base', [CurrencyController::class, 'setBase'])->middleware('perm:super'); // 影响全部价格口径，仅超管
        Route::delete('{id}', [CurrencyController::class, 'destroy'])->middleware('perm:setting:currency:del');
    });

    // 开放接口：给外部系统（ERP、财务、物流）发密钥。接口本身在 routes/open.php
    Route::prefix('open-app')->group(function () {
        Route::get('list', [OpenAppController::class, 'index'])->middleware('perm:setting:open_api:view');
        Route::post('/', [OpenAppController::class, 'store'])->middleware('perm:setting:open_api:add');
        Route::put('{id}', [OpenAppController::class, 'update'])->whereNumber('id')->middleware('perm:setting:open_api:edit');
        Route::post('{id}/reset-key', [OpenAppController::class, 'resetKey'])->whereNumber('id')->middleware('perm:setting:open_api:reset');
        Route::delete('{id}', [OpenAppController::class, 'destroy'])->whereNumber('id')->middleware('perm:setting:open_api:del');
        Route::get('{id}/logs', [OpenAppController::class, 'logs'])->whereNumber('id')->middleware('perm:setting:open_api:view');
        // 主动推送（Webhook）
        Route::post('{id}/webhook-secret', [OpenAppController::class, 'resetWebhookSecret'])->whereNumber('id')->middleware('perm:setting:open_api:reset');
        Route::get('{id}/webhooks', [OpenAppController::class, 'webhooks'])->whereNumber('id')->middleware('perm:setting:open_api:view');
        Route::post('{id}/webhooks/{webhook_id}/resend', [OpenAppController::class, 'resendWebhook'])->whereNumber(['id', 'webhook_id'])->middleware('perm:setting:open_api:resend');
        Route::post('{id}/webhook-test', [OpenAppController::class, 'testWebhook'])->whereNumber('id')->middleware('perm:setting:open_api:resend');
    });

    // 税率设置：按收货地区的税率规则
    Route::prefix('tax-rate')->group(function () {
        Route::get('list', [TaxRateController::class, 'index'])->middleware('perm:setting:tax:view');
        Route::post('/', [TaxRateController::class, 'store'])->middleware('perm:setting:tax:add');
        Route::put('{id}', [TaxRateController::class, 'update'])->whereNumber('id')->middleware('perm:setting:tax:edit');
        Route::delete('{id}', [TaxRateController::class, 'destroy'])->whereNumber('id')->middleware('perm:setting:tax:del');
    });

    // 通用下拉选项（只读）：启用的币种
    Route::get('options/currencies', [OptionController::class, 'currencies'])
        ->middleware('perm:goods:list:view,goods:region:view,setting:currency:view,member:list:edit');

    // 系统设置：基础信息 / 交易设置（分组由 defaults 指定）
    Route::get('config/base', [ConfigController::class, 'show'])->defaults('group', 'base')->middleware('perm:setting:base:view');
    Route::put('config/base', [ConfigController::class, 'save'])->defaults('group', 'base')->middleware('perm:setting:base:edit');
    Route::get('config/trade', [ConfigController::class, 'show'])->defaults('group', 'trade')->middleware('perm:setting:trade:view');
    Route::put('config/trade', [ConfigController::class, 'save'])->defaults('group', 'trade')->middleware('perm:setting:trade:edit');
    Route::get('config/protocol', [ConfigController::class, 'show'])->defaults('group', 'protocol')->middleware('perm:setting:protocol:view');
    Route::put('config/protocol', [ConfigController::class, 'save'])->defaults('group', 'protocol')->middleware('perm:setting:protocol:edit');
    // 小票配置：打印小票的人（订单详情、发货管理）也要能读到它
    Route::get('config/receipt', [ConfigController::class, 'show'])->defaults('group', 'receipt')->middleware('perm:setting:receipt:view,order:ship:print,order:list:detail');
    Route::put('config/receipt', [ConfigController::class, 'save'])->defaults('group', 'receipt')->middleware('perm:setting:receipt:edit');
    Route::get('config/distribution', [ConfigController::class, 'show'])->defaults('group', 'distribution')->middleware('perm:distribution:setting:view');
    Route::put('config/distribution', [ConfigController::class, 'save'])->defaults('group', 'distribution')->middleware('perm:distribution:setting:edit');

    // 消息模板（邮件通知）
    Route::get('message/list', [MessageTemplateController::class, 'index'])->middleware('perm:setting:message:view');
    Route::put('message/{scene}', [MessageTemplateController::class, 'save'])->where('scene', '[a-z_]+')->middleware('perm:setting:message:edit');
    Route::post('message/{scene}/test', [MessageTemplateController::class, 'test'])->where('scene', '[a-z_]+')->middleware(['throttle:20,1', 'perm:setting:message:test']);

    // 接口配置：短信
    Route::get('driver/sms', [DriverController::class, 'index'])->defaults('type', 'sms')->middleware('perm:setting:api_sms:view');
    Route::put('driver/sms/{driver}', [DriverController::class, 'save'])->defaults('type', 'sms')->middleware('perm:setting:api_sms:edit');
    Route::post('driver/sms/{driver}/test', [DriverController::class, 'test'])->defaults('type', 'sms')->middleware('perm:setting:api_sms:test');

    // 接口配置：物流查询
    // 应用 → 小程序（目前只有微信小程序，用于微信一键登录）
    Route::get('driver/app', [DriverController::class, 'index'])->defaults('type', 'app')->middleware('perm:app:mini:view');
    Route::put('driver/app/{driver}', [DriverController::class, 'save'])->defaults('type', 'app')->middleware('perm:app:mini:edit');
    Route::post('driver/app/{driver}/test', [DriverController::class, 'test'])->defaults('type', 'app')->middleware('perm:app:mini:test');
    // 应用 → 第三方登录（目前只有 Google 登录）
    Route::get('driver/oauth', [DriverController::class, 'index'])->defaults('type', 'oauth')->middleware('perm:app:oauth:view');
    Route::put('driver/oauth/{driver}', [DriverController::class, 'save'])->defaults('type', 'oauth')->middleware('perm:app:oauth:edit');
    Route::post('driver/oauth/{driver}/test', [DriverController::class, 'test'])->defaults('type', 'oauth')->middleware('perm:app:oauth:test');

    // 电子面单：服务商的授权信息走通用驱动配置；每家快递的月结账号单独维护。没有“测试连接”（接口没有不产生面单的测试方式）
    Route::get('driver/waybill', [DriverController::class, 'index'])->defaults('type', 'waybill')->middleware('perm:setting:api_waybill:view');
    Route::put('driver/waybill/{driver}', [DriverController::class, 'save'])->defaults('type', 'waybill')->middleware('perm:setting:api_waybill:edit');
    Route::get('waybill/accounts', [WaybillController::class, 'accounts'])->middleware('perm:setting:api_waybill:view');
    Route::post('waybill/accounts', [WaybillController::class, 'store'])->middleware('perm:setting:api_waybill:edit');
    Route::put('waybill/accounts/{id}', [WaybillController::class, 'update'])->where('id', '[0-9]+')->middleware('perm:setting:api_waybill:edit');
    Route::delete('waybill/accounts/{id}', [WaybillController::class, 'destroy'])->where('id', '[0-9]+')->middleware('perm:setting:api_waybill:edit');
    // 发货弹窗用：电子面单能不能用、有哪些快递可选
    Route::get('waybill/options', [WaybillController::class, 'options'])->middleware('perm:order:ship:do');
    Route::get('driver/express', [DriverController::class, 'index'])->defaults('type', 'express')->middleware('perm:setting:api_express:view');
    Route::put('driver/express/{driver}', [DriverController::class, 'save'])->defaults('type', 'express')->middleware('perm:setting:api_express:edit');
    Route::post('driver/express/{driver}/test', [DriverController::class, 'test'])->defaults('type', 'express')->middleware('perm:setting:api_express:test');

    // 接口配置：系统存储
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

    // AI 翻译：翻译接口（有任一带多语言内容的编辑权限即可用：商品、分类、品牌、会员等级、文章/公告/帮助、基础信息、协议）、路由与额度设置、术语表、用量日志
    Route::prefix('translate')->group(function () {
        Route::post('/', [TranslateController::class, 'translate'])
            ->middleware(['throttle:30,1', 'perm:goods:list:add,goods:list:edit,goods:category:add,goods:category:edit,goods:brand:add,goods:brand:edit,member:level:add,member:level:edit,member:broadcast:add,content:article:add,content:article:edit,content:notice:add,content:notice:edit,content:help:add,content:help:edit,setting:base:edit,setting:protocol:edit,setting:api_translate:test']);
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
    // 发票文件（PDF 或图片）：只有能开票的人可以传
    Route::post('upload/invoice', [UploadController::class, 'invoice'])->middleware(['throttle:60,1', 'perm:order:invoice:issue']);

    // 商品：分类、品牌、商品、库存流水
    // ---- 营销：搭配套餐（订单里凑齐一组商品就立减）
    Route::prefix('marketing/bundle')->group(function () {
        Route::get('list', [BundleController::class, 'index'])->middleware('perm:marketing:bundle:view');
        Route::get('skus', [BundleController::class, 'skus'])->middleware('perm:marketing:bundle:add,marketing:bundle:edit');
        Route::post('/', [BundleController::class, 'store'])->middleware('perm:marketing:bundle:add');
        Route::put('{id}', [BundleController::class, 'update'])->where('id', '[0-9]+')->middleware('perm:marketing:bundle:edit');
        Route::post('{id}/status', [BundleController::class, 'status'])->where('id', '[0-9]+')->middleware('perm:marketing:bundle:edit');
        Route::delete('{id}', [BundleController::class, 'destroy'])->where('id', '[0-9]+')->middleware('perm:marketing:bundle:del');
    });

    // ---- 营销：优惠券
    Route::prefix('marketing/coupon')->group(function () {
        Route::get('list', [CouponController::class, 'index'])->middleware('perm:marketing:coupon:view');
        Route::post('/', [CouponController::class, 'store'])->middleware('perm:marketing:coupon:add');
        Route::put('{id}', [CouponController::class, 'update'])->where('id', '[0-9]+')->middleware('perm:marketing:coupon:edit');
        Route::post('{id}/status', [CouponController::class, 'status'])->where('id', '[0-9]+')->middleware('perm:marketing:coupon:edit');
        Route::delete('{id}', [CouponController::class, 'destroy'])->where('id', '[0-9]+')->middleware('perm:marketing:coupon:del');
        Route::post('{id}/grant', [CouponController::class, 'grant'])->where('id', '[0-9]+')->middleware('perm:marketing:coupon:grant');
        Route::get('{id}/records', [CouponController::class, 'records'])->where('id', '[0-9]+')->middleware('perm:marketing:coupon:view');
    });

    Route::prefix('goods')->group(function () {
        Route::prefix('category')->group(function () {
            Route::get('tree', [CategoryController::class, 'tree'])->middleware('perm:goods:category:view,goods:list:view,marketing:coupon:view');
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

        // 商品评价：查看、隐藏 / 恢复显示、商家回复
        Route::get('review/list', [GoodsReviewController::class, 'index'])->middleware('perm:goods:review:view');
        Route::post('review/{id}/status', [GoodsReviewController::class, 'status'])->where('id', '[0-9]+')->middleware('perm:goods:review:edit');
        Route::post('review/{id}/reply', [GoodsReviewController::class, 'reply'])->where('id', '[0-9]+')->middleware('perm:goods:review:reply');

        // 商品本身（放在分类、品牌之后；{id} 只匹配数字，不会和上面的路径冲突）
        // 优惠券页面选“指定商品”时也要搜商品
        Route::get('list', [GoodsController::class, 'index'])->middleware('perm:goods:list:view,marketing:coupon:view');
        Route::get('{id}', [GoodsController::class, 'show'])->middleware('perm:goods:list:view');
        Route::post('/', [GoodsController::class, 'store'])->middleware('perm:goods:list:add');
        Route::put('{id}', [GoodsController::class, 'update'])->middleware('perm:goods:list:edit');
        Route::post('{id}/sale', [GoodsController::class, 'sale'])->middleware('perm:goods:list:sale');
        Route::post('{id}/stock', [GoodsController::class, 'stock'])->middleware('perm:goods:list:stock');
        Route::delete('{id}', [GoodsController::class, 'destroy'])->middleware('perm:goods:list:del');
    });

    // 销售区域（区域定价；商品表单读取区域列表：有商品查看权限即可）
    Route::prefix('sale-region')->group(function () {
        Route::get('list', [SaleRegionController::class, 'index'])->middleware('perm:goods:region:view');
        Route::get('options', [SaleRegionController::class, 'options'])->middleware('perm:goods:region:view,goods:list:view');
        Route::get('cn-divisions', [SaleRegionController::class, 'cnDivisions'])->middleware('perm:goods:region:view,setting:tax:view');
        Route::post('/', [SaleRegionController::class, 'store'])->middleware('perm:goods:region:add');
        Route::put('{id}', [SaleRegionController::class, 'update'])->middleware('perm:goods:region:edit');
        Route::post('{id}/status', [SaleRegionController::class, 'status'])->middleware('perm:goods:region:edit');
        Route::delete('{id}', [SaleRegionController::class, 'destroy'])->middleware('perm:goods:region:del');
    });

    // 内容：文章 / 公告 / 帮助中心共用一个控制器，类型由 defaults 指定；公告没有分类
    foreach (['article' => 1, 'notice' => 2, 'help' => 3] as $slug => $type) {
        Route::prefix("content/{$slug}")->group(function () use ($slug, $type) {
            $perm = "perm:content:{$slug}";
            if ($type !== 2) {
                Route::get('category/list', [ArticleController::class, 'categories'])->defaults('type', $type)->middleware("{$perm}:view");
                Route::post('category', [ArticleController::class, 'storeCategory'])->defaults('type', $type)->middleware("{$perm}:add");
                Route::put('category/{id}', [ArticleController::class, 'updateCategory'])->defaults('type', $type)->middleware("{$perm}:edit");
                Route::delete('category/{id}', [ArticleController::class, 'destroyCategory'])->defaults('type', $type)->middleware("{$perm}:del");
            }
            Route::get('list', [ArticleController::class, 'index'])->defaults('type', $type)->middleware("{$perm}:view");
            Route::get('{id}', [ArticleController::class, 'show'])->defaults('type', $type)->middleware("{$perm}:view");
            Route::post('/', [ArticleController::class, 'store'])->defaults('type', $type)->middleware("{$perm}:add");
            Route::put('{id}', [ArticleController::class, 'update'])->defaults('type', $type)->middleware("{$perm}:edit");
            Route::post('{id}/status', [ArticleController::class, 'status'])->defaults('type', $type)->middleware("{$perm}:edit");
            Route::post('{id}/top', [ArticleController::class, 'top'])->defaults('type', $type)->middleware("{$perm}:edit");
            Route::delete('{id}', [ArticleController::class, 'destroy'])->defaults('type', $type)->middleware("{$perm}:del");
        });
    }

    // 内容：广告位与广告（轮播图）
    Route::prefix('content/ad')->group(function () {
        Route::get('position/list', [AdController::class, 'positions'])->middleware('perm:content:ad:view');
        Route::post('position', [AdController::class, 'storePosition'])->middleware('perm:content:ad:add');
        Route::put('position/{id}', [AdController::class, 'updatePosition'])->middleware('perm:content:ad:edit');
        Route::delete('position/{id}', [AdController::class, 'destroyPosition'])->middleware('perm:content:ad:del');
        Route::get('list', [AdController::class, 'index'])->middleware('perm:content:ad:view');
        Route::post('/', [AdController::class, 'store'])->middleware('perm:content:ad:add');
        Route::put('{id}', [AdController::class, 'update'])->middleware('perm:content:ad:edit');
        Route::post('{id}/status', [AdController::class, 'status'])->middleware('perm:content:ad:edit');
        Route::delete('{id}', [AdController::class, 'destroy'])->middleware('perm:content:ad:del');
    });

    // 数据中心（只读统计）
    Route::prefix('stats')->middleware('perm:data:overview:view')->group(function () {
        Route::get('overview', [StatsController::class, 'overview']);
        Route::get('trend', [StatsController::class, 'trend']);
        Route::get('goods-rank', [StatsController::class, 'goodsRank']);
        Route::get('breakdown', [StatsController::class, 'breakdown']);
    });

    // 分销：分销员、关系、佣金明细、提现（审核、打款各自独立权限点）
    Route::prefix('distribution')->group(function () {
        Route::get('distributor/list', [DistributionController::class, 'distributors'])->middleware('perm:distribution:distributor:view');
        Route::post('distributor/{id}/audit', [DistributionController::class, 'audit'])->middleware('perm:distribution:distributor:audit');
        Route::post('distributor/{id}/status', [DistributionController::class, 'status'])->middleware('perm:distribution:distributor:status');
        Route::post('distributor/{id}/appoint', [DistributionController::class, 'appoint'])->middleware('perm:distribution:distributor:audit');
        Route::get('relation/{id}', [DistributionController::class, 'relation'])->middleware('perm:distribution:distributor:view');
        Route::put('relation/{id}', [DistributionController::class, 'setParent'])->middleware('perm:distribution:distributor:edit');
        Route::get('commission/list', [DistributionController::class, 'commissions'])->middleware('perm:distribution:commission:view');
        Route::get('withdraw/list', [DistributionController::class, 'withdraws'])->middleware('perm:distribution:withdraw:view');
        Route::post('withdraw/{id}/audit', [DistributionController::class, 'withdrawAudit'])->middleware('perm:distribution:withdraw:audit');
        Route::post('withdraw/{id}/pay', [DistributionController::class, 'withdrawPaid'])->middleware('perm:distribution:withdraw:paid');
    });

    // 运费模板（商品表单的下拉：有商品查看权限即可读取）
    Route::prefix('shipping-template')->group(function () {
        Route::get('list', [ShippingTemplateController::class, 'index'])->middleware('perm:setting:shipping:view');
        Route::get('options', [ShippingTemplateController::class, 'options'])->middleware('perm:setting:shipping:view,goods:list:view');
        Route::get('{id}', [ShippingTemplateController::class, 'show'])->middleware('perm:setting:shipping:view');
        Route::post('/', [ShippingTemplateController::class, 'store'])->middleware('perm:setting:shipping:add');
        Route::put('{id}', [ShippingTemplateController::class, 'update'])->middleware('perm:setting:shipping:edit');
        Route::delete('{id}', [ShippingTemplateController::class, 'destroy'])->middleware('perm:setting:shipping:del');
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
        Route::get('level/options', [MemberLevelController::class, 'options'])->middleware('perm:member:level:view,member:list:view,member:broadcast:view');
        Route::post('level', [MemberLevelController::class, 'store'])->middleware('perm:member:level:add');
        Route::put('level/{id}', [MemberLevelController::class, 'update'])->middleware('perm:member:level:edit');
        Route::delete('level/{id}', [MemberLevelController::class, 'destroy'])->middleware('perm:member:level:del');

        // 群发消息（站内信）：建好后由定时任务分批发，“立即发送”是手动触发同一段逻辑
        Route::get('broadcast/list', [MessageBroadcastController::class, 'index'])->middleware('perm:member:broadcast:view');
        Route::post('broadcast', [MessageBroadcastController::class, 'store'])->middleware(['throttle:20,1', 'perm:member:broadcast:add']);
        Route::post('broadcast/{id}/send', [MessageBroadcastController::class, 'send'])->where('id', '[0-9]+')->middleware('perm:member:broadcast:add');
        Route::post('broadcast/{id}/revoke', [MessageBroadcastController::class, 'revoke'])->where('id', '[0-9]+')->middleware('perm:member:broadcast:revoke');

        Route::get('points', [MemberPointController::class, 'index'])->middleware('perm:member:points:view,member:list:detail');
    });

    // 订单：列表/详情/备注/改价/取消；发货管理（分批发货、改单号）；确认收货算发货环节的权限
    Route::prefix('order')->group(function () {
        // 发票管理：开票是登记发票号码和文件（发票在商家自己的开票软件里开），不对接税控
        Route::get('invoice/list', [OrderInvoiceController::class, 'index'])->middleware('perm:order:invoice:view');
        Route::post('invoice/{id}/issue', [OrderInvoiceController::class, 'issue'])->where('id', '[0-9]+')->middleware('perm:order:invoice:issue');
        Route::post('invoice/{id}/reject', [OrderInvoiceController::class, 'reject'])->where('id', '[0-9]+')->middleware('perm:order:invoice:reject');
        Route::post('invoice/{id}/void', [OrderInvoiceController::class, 'void'])->where('id', '[0-9]+')->middleware('perm:order:invoice:void');

        Route::get('list', [OrderController::class, 'index'])->middleware('perm:order:list:view');
        Route::get('counts', [OrderController::class, 'counts'])->middleware('perm:order:list:view');
        Route::get('list/export', [OrderController::class, 'export'])->middleware(['throttle:10,1', 'perm:order:list:export']);
        Route::get('list/{id}', [OrderController::class, 'show'])->middleware('perm:order:list:detail,order:ship:view');
        Route::post('list/{id}/remark', [OrderController::class, 'remark'])->middleware('perm:order:list:remark');
        Route::post('list/{id}/price', [OrderController::class, 'price'])->middleware('perm:order:list:price');
        Route::post('list/{id}/cancel', [OrderController::class, 'cancel'])->middleware('perm:order:list:cancel');
        Route::post('list/{id}/receive', [OrderController::class, 'receive'])->middleware('perm:order:ship:edit');

        Route::get('trade/list', [OrderTradeController::class, 'index'])->middleware('perm:order:trade:view');
        Route::get('notify/list', [OrderNotifyController::class, 'index'])->middleware('perm:order:notify:view');
        Route::get('notify/{id}', [OrderNotifyController::class, 'show'])->middleware('perm:order:notify:view');
        Route::post('notify/{id}/retry', [OrderNotifyController::class, 'retry'])->middleware(['throttle:30,1', 'perm:order:notify:retry']);

        // 售后订单：审核、确认收到退货、执行退款各自独立权限点
        // 商家代买家发起售后（{id} 是订单 ID），发起后直接视为审核通过
        Route::get('list/{id}/refundable', [OrderRefundController::class, 'refundable'])->middleware('perm:order:refund:apply');
        Route::post('list/{id}/refund', [OrderRefundController::class, 'apply'])->middleware(['throttle:30,1', 'perm:order:refund:apply']);
        Route::get('refund/list', [OrderRefundController::class, 'index'])->middleware('perm:order:refund:view');
        Route::get('refund/counts', [OrderRefundController::class, 'counts'])->middleware('perm:order:refund:view');
        Route::get('refund/{id}', [OrderRefundController::class, 'show'])->middleware('perm:order:refund:view');
        Route::post('refund/{id}/audit', [OrderRefundController::class, 'audit'])->middleware('perm:order:refund:audit');
        Route::post('refund/{id}/confirm', [OrderRefundController::class, 'confirm'])->middleware('perm:order:refund:confirm');
        Route::post('refund/{id}/pay', [OrderRefundController::class, 'pay'])->middleware(['throttle:30,1', 'perm:order:refund:pay']);

        Route::get('ship/list', [OrderShipController::class, 'index'])->middleware('perm:order:ship:view');
        Route::get('ship/express', [OrderShipController::class, 'express'])->middleware('perm:order:ship:view,order:ship:do,order:ship:edit');
        Route::post('ship/{id}', [OrderShipController::class, 'ship'])->middleware('perm:order:ship:do');
        Route::put('ship/record/{shippingId}', [OrderShipController::class, 'editExpress'])->middleware('perm:order:ship:edit');
        // 物流轨迹：带 refresh=1 时重新向服务商查询（同一个包裹 5 分钟内最多查一次）
        Route::get('ship/record/{shippingId}/track', [OrderShipController::class, 'track'])->middleware(['throttle:60,1', 'perm:order:list:detail,order:ship:view']);
    });
});
