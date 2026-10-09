# gmmall 多语言分销商城（交接文档，供 Claude Code 本地继续开发）

## 目录
- `home/`：后端 API（Laravel 13 + PHP 8.5 + MySQL 8）
- `admin-web/`：商城管理后台前端（Vue3 + Element Plus + Vite + Pinia）
- 本项目是 **Laravel**，不是 ThinkPHP；个人全局配置里若有 ThinkPHP 路径（`app/logic` 等），以本文件为准。

## 业务范围
参照 CRMEB v6 的功能结构，代码自行重写。支持 PC / App / 小程序 / H5，优先 H5 和小程序，App 保持兼容。
模块：商品、订单、分销（最多 2 级，可配 1 或 2 级）、会员、内容、数据中心、应用（公众号/小程序/App）、设置。
设置含：系统设置、消息、协议、小票、管理员权限、运费、API 配置（短信、存储、物流查询、电子面单、支付）。
测试服务器在国内，生产在美国/新加坡。

## 路由与目录约定
- `/api` → `routes/api.php`（C 端）；`/admin-api` → `routes/admin.php`（后台，`middleware('api')`）；`/notify` → `routes/notify.php`（第三方回调）。
- 数据表前缀 `gm_` 由 `config/database.php` 配置，Eloquent `$table` 不写前缀。
- 分层（强制）：middleware → controller → FormRequest → service → model。控制器里禁止 `DB::` 和直接查模型。
  - `app/Http/Controllers/{Admin,Api,Notify}`、`app/Http/Requests/{Admin,Api}`
  - `app/Services/{Admin,Api,Common}`（Common 为端间共享的业务）
  - `app/Models`（继承 `BaseModel`，`guarded=[]`，赋值由 Service 控制）
- 返回统一 `{code,msg,data}`：`ApiResponse::ok/fail`；业务错误抛 `BusinessException`。
- **读取路由参数一律用 `$request->route('name')`**。Laravel 按位置注入路由参数，且默认值排在路径参数之后，用方法参数名会错位（已踩过坑：type/driver 互换）。
- 代码风格：PSR-12、类型声明、中文注释。已有文件多数没写 `strict_types`，新文件按项目现状保持一致。

## 关键业务规则（不要破坏）
- **金额**：只用 bcmath 字符串，经 `App\Support\Money`（round 半入、fromBase、toBase、mul），禁止 float。
- **多币种**：订单固化币种、汇率、单价和标题快照。单价只经 `PriceService::unitPrices`（目录、购物车、结算预览、下单共用），取价顺序：销售区域价 → `gm_goods_price` 该币种单独定价 → 基准价 × 汇率，按币种小数位四舍五入。
- **区域定价**：销售区域（`gm_sale_region` + `gm_sale_region_area`）国内按省/市、境外按国家划分，一个地区只属于一个区域；SKU 区域价存 `gm_goods_region_price`，按区域的定价币种填写，其他币种结算时用 `Money::convert` 换算。匹配只经 `SaleRegionService::match`（带缓存，后台改动后须 `flush()`）：国内 省+市 > 整省，境外按国家码；只取命中的那一个区域，该 SKU 在这个区域没填价就按标准价，不会再退到整省的区域。**最终价以收货地址为准**：下单和结算预览按所选地址取区域并记到订单 `price_region_id/name`；浏览、购物车按会员默认收货地址展示，未登录或无地址显示标准价。
- **国内省市名称**以 `config/cn_divisions.php` 的标准名单为准：销售区域配置和国内收货地址（`AddressService::save`）都校验，C 端地址表单用 `GET /api/config/cn-divisions` 取名单。
- **多语言**：主表 + `_lang` 表，用 `TranslationService`（fetch/sync/assertDefaultFilled/resolve）；语言/币种用 `LocaleService`（带缓存，后台改动后须 `flush()`）；`SetLocale` 读取 `X-Lang`、`X-Currency`。
- **订单状态**只能经 `OrderStatusService::transit`（乐观锁 + `gm_order_status_log`），不要在别处直接改 `status`。
  状态：0 待支付、1 待发货、2 待收货、3 已完成、4 已取消、5 售后中、6 已关闭；支付状态：0 未支付、1 已支付、2 部分退款、3 已退款。
- **库存**：`OrderStockService::deduct`（按 SKU id 升序加锁）/ `restore`（按 ref_no 回补净扣减量，幂等）。
- **支付**：“已支付”唯一入口 `OrderPaidService::markPaid`（幂等）。`PayService::settle` 要求金额与币种一致且订单仍可支付，否则标记“需人工处理”不改订单。回调日志以 `(channel,event_id)` 唯一键幂等；异常返回 HTTP 500 让渠道重试；`mall:pay-compensate` 做补偿轮询。
- **驱动模式**：`DriverRegistry`（type→驱动→字段）、`DriverManager`、`DriverConfig`（`encrypted:array`）、`DriverConfigService`；后台用通用驱动配置页。
- **后台权限**：`admin.auth` 令牌（sha256 哈希、滑动过期）；路由 `perm:` 中间件（逗号 = 任一）；敏感操作单独权限点；前端 `v-perm` 和 `hasPerm`。菜单和权限点在 `MenuSeeder`。
- **C 端**：`api.token`（必须登录）/ `api.token:optional`；价格服务端算，客户端不传价；请求头 `X-Lang`、`X-Currency`、`X-Source`(h5/mp/app/pc)。
- **运费**：只经 `ShippingFeeService::calc`（结算预览和下单共用）。商品 `shipping_template_id=0` 或模板“默认包邮”则免运费；同模板商品合并按件/重量/体积套首续费；地区匹配 省/州 > 国家 > `*`，无匹配规则则拒绝下单；模板金额为基准币种，汇总后折算下单币种。
- 修改数据库结构前先向我确认。

## 前端要点
- 菜单驱动的动态路由，加载 `views/<menu.component>/index.vue`。
- 模板里不要在数据未填充时读取 `settings.x[code].y` 这类深层路径（会抛错并导致切换页面后内容区残留）。

## 已完成
管理员/角色/菜单权限、系统设置、存储驱动、商品（分类/品牌/规格/SKU/分币种定价）、AI 翻译（GPT、DeepSeek，开关式启用）、会员（等级/积分/余额）、订单核心与后台管理（发货、超时关闭、自动收货）、支付框架（Stripe、PayPal，含回调/补偿/流水页面）、C 端 API v1（注册登录、商品、地址、购物车、结算、下单、订单、支付发起/确认）、运费模板（后台管理页、商品绑定、结算预览与下单计费）、区域定价（销售区域管理、商品区域价、按收货地址取价）。

## 待办（建议顺序）
1. ~~运费模板~~（已完成，见“已完成”）。
2. 支付宝、微信支付驱动（微信需小程序 openid，需先做微信登录）。
3. 短信驱动 + 注册/登录验证码（目前注册无验证码，**上线前必须补**）。
4. 退款与售后流程（Stripe/PayPal 驱动已有 `refund`）、积分抵扣、订单导出（权限点已有）。
5. 物流查询驱动、电子面单、分销（最多 2 级）、内容、数据中心、应用（公众号/小程序/App）。
6. uni-app C 端、PC 端。
7. 二期 AI 翻译（Claude、Qwen）；分类/品牌/规格名的翻译按钮与批量填充。

## 验证状态（重要）
这些代码是在没有 Laravel 运行环境的沙箱里写的，**只做过 `php -l` 语法检查，没有真正跑过**。接手后第一件事：
1. `composer install`、配置 `.env`、`php artisan migrate`，`php artisan route:list` 确认路由。
2. 逐模块冒烟测试：登录 → 商品 → 地址 → 购物车 → 下单 → 取消/超时关闭 → 支付（Stripe/PayPal 沙箱密钥）。
3. 补 PHPUnit/Pest 测试，重点：金额计算、库存扣减/回补、支付幂等与回调重放。
4. `cd admin-web && npm install && npm run build` 确认前端能构建（沙箱里没构建过）。

## 常用命令
- 迁移：`php artisan migrate`；若旧表是手工导入 `gm_mall_step1.sql`，只跑新增迁移用 `--path=`。
- 定时任务：`mall:close-expired-orders`、`mall:auto-receive`、`mall:pay-compensate`（需配置调度）。
- 联调命令：`mall:dev-order {member_id} {sku_id} {qty}`（造测试订单，`--paid` 模拟已付）、`mall:dev-pay {order_no} {stripe|paypal}`（发起支付，`--confirm` 确认入账）。
   ## 附件目录
   - `下载附件/` 只存放云端交付的压缩包（gm_*.zip 等）。已解压并合并进 `home/` 和 `admin-web/`，不要从这里读取代码，也不要修改或删除里面的文件。以 `home/`、`admin-web/` 里的代码为准。