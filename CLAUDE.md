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
- **区域定价**：销售区域（`gm_sale_region` + `gm_sale_region_area`）国内按省/市、境外按国家划分，一个地区只属于一个区域；SKU 区域价存 `gm_goods_region_price`，按区域的定价币种填写，其他币种结算时用 `Money::convert` 换算。匹配只经 `SaleRegionService::match`（带缓存，后台改动后须 `flush()`）：国内 省+市 > 整省，境外按国家码；市级和整省区域同时命中时返回市级区域并带 `fallback`（整省区域），`unitPrices` 逐级回退：市级区域价 → 整省区域价 → 标准价。订单上记录的是最具体的那个区域。**最终价以收货地址为准**：下单和结算预览按所选地址取区域并记到订单 `price_region_id/name`；浏览、购物车按会员默认收货地址展示，未登录或无地址显示标准价。
- **国内省市名称**以 `config/cn_divisions.php` 的标准名单为准：销售区域配置和国内收货地址（`AddressService::save`）都校验，C 端地址表单用 `GET /api/config/cn-divisions` 取名单。
- **多语言**：主表 + `_lang` 表，用 `TranslationService`（fetch/sync/assertDefaultFilled/resolve）；语言/币种用 `LocaleService`（带缓存，后台改动后须 `flush()`）；`SetLocale` 读取 `X-Lang`、`X-Currency`。
- **订单状态**只能经 `OrderStatusService::transit`（乐观锁 + `gm_order_status_log`），不要在别处直接改 `status`。
  状态：0 待支付、1 待发货、2 待收货、3 已完成、4 已取消、5 售后中、6 已关闭；支付状态：0 未支付、1 已支付、2 部分退款、3 已退款。
- **库存**：`OrderStockService::deduct`（按 SKU id 升序加锁）/ `restore`（按 ref_no 回补净扣减量，幂等）。
- **支付**：“已支付”唯一入口 `OrderPaidService::markPaid`（幂等）。`PayService::settle` 要求金额与币种一致且订单仍可支付，否则标记“需人工处理”不改订单。回调日志以 `(channel,event_id)` 唯一键幂等；异常返回 HTTP 500 让渠道重试；`mall:pay-compensate` 做补偿轮询。
- **售后/退款**：售后单状态只能经 `OrderRefundService`（申请、撤销、填退货物流、审核、确认收货、执行退款）。仅退款：待审核 → 退款中 → 已完成；退货退款：待审核 → 待买家退货 → 待确认收货 → 退款中 → 已完成。有进行中的售后时订单为“售后中”，结束后由 `restoreOrder` 恢复（商品全部退完则关闭），支付状态按已退金额变为部分退款/已退款。整单退款只用于未发过货且没有过售后的订单（含运费）；单品可退上限按商品金额占比分摊实付（不含运费），商家审核时可改金额。未发货的退款和退货退款完成时回补库存（`OrderStockService::restoreQty`）。在线支付的订单调驱动 `refund` 原路退回，渠道返回处理中时售后单停在“退款中”，再次执行即查询结果（靠驱动的幂等键）；线下收款只能登记“线下已退款”。退款写一条 `trade_type=2` 的 `gm_pay_trade`。
- **积分抵扣**：只经 `OrderPointsService`（结算预览和下单共用 `quote`）。交易设置里开关（默认关）、兑换比例 `points_per_unit`（多少积分抵 1 元基准币种）、上限 `points_max_percent`（占“商品金额 - 会员折扣”的百分比，运费不参与）。抵扣金额固化在订单 `points_amount`，实扣积分记流水（type=4，ref_no=订单号）；订单取消、超时关闭、商品全部退款时 `giveBack` 按流水净额退还（幂等），部分退款不退。恒等式：商品 + 运费 - 优惠 - 积分 = 应付。
- **短信验证码**：只经 `SmsCodeService`（`send` / `verify`），用途 register / login / reset。验证码由短信平台生成并校验（驱动 `GmSmsDriver` 调平台的 verify-code 接口），商城不存验证码；本地限流：同号 60 秒一次、每天 10 次，同 IP 每小时 20 次，同号同用途错 5 次锁 10 分钟。只支持中国大陆手机号。后台启用短信驱动后，手机号注册必须带验证码（境外手机号不能注册，用邮箱），并开放短信登录和短信找回密码；未启用时手机号注册不校验。C 端用 `config/init` 的 `sms_enabled` 判断。**密钥只存 `gm_driver_config`（加密），不要写进代码、.env 示例或日志。**
- **邮箱验证码**：只经 `EmailCodeService`（`send` / `verify`），用途 register / reset。验证码由商城生成，缓存里只存 HMAC 哈希，10 分钟有效、校验通过即失效、按用途隔离；限流与防爆破规则同短信。开关是基础设置的 `email_verify_enabled`（默认关）：开启后邮箱注册必须带验证码，并开放邮箱找回密码。发信走 Laravel 邮件配置，邮件模板 `App\Mail\VerifyCodeMail`（中文语言发中文，其余发英文）。C 端用 `config/init` 的 `email_verify_enabled` 判断。
- **分销**：规则只在 `Common\DistributionService`。关系：注册时凭邀请码（`inviteCode`，会员 ID + 校验位，不占字段）绑定 `member.parent_id`，之后只能后台改（防成环）。资格：会员申请、后台审核（`gm_distributor`，同时是佣金账户），后台也可直接指定。层级 1 或 2 级：一级 = 买家的直接上级，二级 = 上级的上级；上级不是正常状态的分销员就跳过、不顺延，自购不返佣。计佣：`OrderPaidService::markPaid` 里调 `onOrderPaid` 生成待结算佣金，基数 = 商品 - 会员折扣 - 积分抵扣（不含运费）折基准币种，`(order_id, level)` 唯一保证幂等。退款：`OrderRefundService::finish` 里调 `onRefund` 按已退商品款占比重算，全部退完作废，已结算的也扣回。结算：`mall:distribution-settle`（订单完成且过售后期、无进行中售后）转为可提现并写 `gm_distribution_log`。提现：申请时可提现 → 提现中，后台审核、线下打款后登记；收款信息加密存储。配置在 `distribution` 分组（默认关闭）。
- **数据统计**：只读，全部在 `AdminStatsService`。金额折基准币种（订单用 `base_pay_amount`，退款和商品金额按订单固化汇率折算）；支付口径按 `paid_at`、含之后退款的订单，退款金额按完成时间单独统计；日期按 `config(app.timezone)`（目前 UTC）的自然日。带表别名的原生 SQL 要自己拼表前缀（`DB::getTablePrefix()`），查询构造器不会替原生片段加前缀。趋势图用 `components/TrendChart.vue`（单序列折线，一张图一个指标，不做双轴）。
- **协议与政策**：内容存 `gm_config` 的 `protocol` 分组（多语言富文本），后台“设置 → 协议管理”维护，C 端 `GET /api/protocols`（只返回已填写的）和 `GET /api/protocols/{key}`，当前语言没填时回退默认语言。注意 `gm_config.value` 是 TEXT：一个协议所有语言合计编码后不能超过 60000 字节（`ConfigService::save` 会拦截），语言多了或协议很长时需要把该列改成 MEDIUMTEXT。
- **驱动模式**：`DriverRegistry`（type→驱动→字段）、`DriverManager`、`DriverConfig`（`encrypted:array`）、`DriverConfigService`；后台用通用驱动配置页。
- **后台权限**：`admin.auth` 令牌（sha256 哈希、滑动过期）；路由 `perm:` 中间件（逗号 = 任一）；敏感操作单独权限点；前端 `v-perm` 和 `hasPerm`。菜单和权限点在 `MenuSeeder`。
- **C 端**：`api.token`（必须登录）/ `api.token:optional`；价格服务端算，客户端不传价；请求头 `X-Lang`、`X-Currency`、`X-Source`(h5/mp/app/pc)。
- **运费**：只经 `ShippingFeeService::calc`（结算预览和下单共用）。商品 `shipping_template_id=0` 或模板“默认包邮”则免运费；同模板商品合并按件/重量/体积套首续费；地区匹配 省/州 > 国家 > `*`，无匹配规则则拒绝下单；模板金额为基准币种，汇总后折算下单币种。
- 修改数据库结构前先向我确认。

## 前端要点
- 菜单驱动的动态路由，加载 `views/<menu.component>/index.vue`。
- 模板里不要在数据未填充时读取 `settings.x[code].y` 这类深层路径（会抛错并导致切换页面后内容区残留）。

## 已完成
管理员/角色/菜单权限、系统设置、存储驱动、商品（分类/品牌/规格/SKU/分币种定价）、AI 翻译（GPT、DeepSeek，开关式启用）、会员（等级/积分/余额）、订单核心与后台管理（发货、超时关闭、自动收货）、支付框架（Stripe、PayPal，含回调/补偿/流水页面）、C 端 API v1（注册登录、商品、地址、购物车、结算、下单、订单、支付发起/确认）、运费模板（后台管理页、商品绑定、结算预览与下单计费）、区域定价（销售区域管理、商品区域价、按收货地址取价）、售后与退款（C 端申请/撤销/退货物流接口、后台售后订单页）、下单积分抵扣、订单导出 CSV、短信与邮箱验证码、分销（邀请关系、分销员审核、佣金、结算、提现）、数据中心（后台首页与数据概览页）、协议管理（用户协议、隐私政策等 5 种，多语言）。

## 待办（建议顺序）
1. ~~运费模板~~（已完成，见“已完成”）。
2. 支付宝、微信支付驱动（微信需小程序 openid，需先做微信登录）。
3. ~~短信驱动 + 注册/登录验证码~~（已完成，对接 GM 短信平台，文档见 `docs/短信平台接入文档.md`；**还没和真实平台联调过**，场景标识待平台确认）。邮箱验证码也已完成（发信要先配 `.env` 的 `MAIL_*`，没有用真实邮箱发过）。
4. ~~退款与售后流程~~（已完成；Stripe/PayPal 真实退款还没用沙箱密钥验证，渠道退款回调 `refunded` 仍只记录不入账，后台也还不能代买家发起退款）、~~积分抵扣~~、~~订单导出~~（均已完成；“消费得积分”和退款扣回积分还没做）。
5. 物流查询驱动、电子面单、~~分销（最多 2 级）~~（已完成；佣金比例只有全局的，还不能按商品单独设）、内容、~~数据中心~~（已完成：概览、趋势、商品排行、订单分布）、应用（公众号/小程序/App）。
6. uni-app C 端、PC 端。
7. 二期 AI 翻译（Claude、Qwen）；分类/品牌/规格名的翻译按钮与批量填充。

## 验证状态（重要）
最初的代码是在没有 Laravel 运行环境的沙箱里写的，只做过 `php -l` 语法检查。2026-10-09 起已在本地跑通：下面第 1、4 步完成；第 2 步除真实支付（缺 Stripe/PayPal 沙箱密钥）外都走过真实 HTTP 链路；第 3 步只有纯计算的单元测试（运费、区域匹配、币种换算、退款分摊、积分抵扣），还没有带数据库的功能测试。后台页面：2026-10-09 用无头 Edge 逐页截图看过运费模板、销售区域、售后订单、分销四页、短信、交易设置、基础信息、首页、数据概览的列表态，渲染正常；弹窗、抽屉和表单提交没有在浏览器里点过。原始清单：
1. `composer install`、配置 `.env`、`php artisan migrate`，`php artisan route:list` 确认路由。
2. 逐模块冒烟测试：登录 → 商品 → 地址 → 购物车 → 下单 → 取消/超时关闭 → 支付（Stripe/PayPal 沙箱密钥）。
3. 补 PHPUnit/Pest 测试，重点：金额计算、库存扣减/回补、支付幂等与回调重放。
4. `cd admin-web && npm install && npm run build` 确认前端能构建（沙箱里没构建过）。

## 常用命令
- 迁移：`php artisan migrate`；若旧表是手工导入 `gm_mall_step1.sql`，只跑新增迁移用 `--path=`。
- 定时任务：`mall:close-expired-orders`、`mall:auto-receive`、`mall:pay-compensate`、`mall:distribution-settle`，调度已写在 `routes/console.php`；服务器上要加一条每分钟执行 `php artisan schedule:run` 的 cron 才会生效。
- 联调命令：`mall:dev-order {member_id} {sku_id} {qty}`（造测试订单，`--paid` 模拟已付）、`mall:dev-pay {order_no} {stripe|paypal}`（发起支付，`--confirm` 确认入账）。
   ## 附件目录
   - `下载附件/` 只存放云端交付的压缩包（gm_*.zip 等）。已解压并合并进 `home/` 和 `admin-web/`，不要从这里读取代码，也不要修改或删除里面的文件。以 `home/`、`admin-web/` 里的代码为准。