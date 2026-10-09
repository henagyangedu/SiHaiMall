# gmmall 多语言分销商城（交接文档，供 Claude Code 本地继续开发）

## 目录
- `home/`：后端 API（Laravel 13 + PHP 8.5 + MySQL 8）
- `admin-web/`：商城管理后台前端（Vue3 + Element Plus + Vite + Pinia）
- `mall-uni/`：C 端（uni-app，Vue3 + Vite，命令行工程，不依赖 HBuilderX）。`npm run dev:h5` 开发（端口 5180，/api 走代理，后端地址在 `.env.development`），`npm run build:h5` / `build:mp-weixin` 构建
- `mall-pc/`：PC 端（Vue3 + Vite + Element Plus + vue-router，hash 路由）。`npm run dev` 开发（端口 5190，`/api`、`/uploads` 走代理），`npm run build` 构建到 `dist/`
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
- 代码风格：PSR-12、类型声明、中文注释。已有文件多数没写 `strict_types`，新文件按项目现状保持一致。**不要对 `app/` 下已有文件跑 Pint**（会按 Laravel 预设整体重排，和现有写法不一致）；`tests/` 下的文件是用 Pint 格式化过的。

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
- **支付单的渠道单号**：`gm_pay_trade` 对 (channel, channel_trade_no, trade_type) 有唯一约束，所以还没拿到渠道单号的支付单用商城自己的 `trade_no` 占位（留空会让同一渠道的第二笔支付撞唯一键）。**读渠道单号一律用 `PayTrade::channelNo()`**（占位时返回空串），不要直接读字段。
- **微信支付**（`WechatDriver`，API v3 直连商户，未用真实商户号联调）：只收人民币；小程序里是 JSAPI 支付（C 端发起支付时带 `wx_code`，驱动用它换 openid，只用于这一次，不保存、不绑定会员），手机浏览器里是 H5 支付（微信内置浏览器里会被拒绝并提示）。回调用“微信支付公钥”验签（不是平台证书模式）、APIv3 密钥解密，回调地址 `{APP_URL}/notify/pay/wechat`，所以 **`APP_URL` 必须是公网 HTTPS 域名**，本地收不到回调，只能靠查单（`GET /api/pay/{trade_no}/confirm`、`mall:pay-compensate`）。各渠道自身的限制（能用在哪些来源端、收哪些币种）在 `PayService::LIMITS`：微信只在 mp / h5 出现，Stripe / PayPal 这类跳转收银台的不在小程序里出现。微信的支付凭证几分钟就过期，所以每次发起都重新下单（`NO_REUSE`）。后台配置里的多行密钥用字段类型 `secret_text`（私钥，不回显）和 `textarea`（公钥，回显）。测试用的密钥在 `tests/Fixtures/wechat`，是随手生成、仅供测试的，和任何真实商户无关。
- **支付宝**（`AlipayDriver`，开放平台网关，公钥模式 RSA2，未用真实应用联调）：只收人民币；手机浏览器用“手机网站支付”、电脑浏览器用“电脑网站支付”，发起支付只是生成一个带签名的网关跳转地址（不请求支付宝），小程序里不出现。异步通知是表单参数，用“支付宝公钥”验签，处理成功必须应答纯文本 `success`；通知地址 `{APP_URL}/notify/pay/alipay`。退款同步出结果；**退款引起的通知（带 `refund_fee` / `out_biz_no`）只记录不处理**——全额退款后支付宝会通知 `TRADE_CLOSED`，不能当成支付关闭。支付宝没有退款单号，售后单的 `refund_trade_no` 存的是支付宝交易号。应用私钥、支付宝公钥可以是不带 PEM 头尾的一长串 Base64（密钥工具给的就是这种）。
- **售后/退款**：售后单状态只能经 `OrderRefundService`（申请、撤销、填退货物流、审核、确认收货、执行退款）。仅退款：待审核 → 退款中 → 已完成；退货退款：待审核 → 待买家退货 → 待确认收货 → 退款中 → 已完成。有进行中的售后时订单为“售后中”，结束后由 `restoreOrder` 恢复（商品全部退完则关闭），支付状态按已退金额变为部分退款/已退款。整单退款只用于未发过货且没有过售后的订单（含运费）；单品可退上限按商品金额占比分摊实付（不含运费），商家审核时可改金额。未发货的退款和退货退款完成时回补库存（`OrderStockService::restoreQty`）。在线支付的订单调驱动 `refund` 原路退回，渠道返回处理中时售后单停在“退款中”并记下渠道退款单号（`refund_trade_no`），之后有三条路完成它：渠道的退款回调（`PayService::dispatch` → `completeByNotify`，核对售后单号、金额、币种）、定时任务 `mall:refund-compensate`、管理员再点“执行退款”——后两者调驱动的 `queryRefund` 查询，**不是重发退款**（带同一个幂等键重发，渠道只会原样返回第一次的应答）。渠道明确失败时清掉渠道退款单号，下次点击重新发起。回调里没带商城售后单号的退款（在渠道后台手工退的）只记录、不自动处理；线下收款只能登记“线下已退款”。退款写一条 `trade_type=2` 的 `gm_pay_trade`。**商家代买家发起**：后台订单列表的“售后”按钮（权限点 `order:refund:apply`，接口 `GET /admin-api/order/list/{订单ID}/refundable`、`POST .../refund`），走 `OrderRefundService::applyByAdmin` = 申请 + 直接审核通过（仅退款进“退款中”，仍需到售后订单页执行退款；退货退款进“待买家退货”）。规则和买家申请相同，只有两点不同：不受售后申请期限限制、原因前面加“商家发起：”。
- **积分抵扣**：只经 `OrderPointsService`（结算预览和下单共用 `quote`）。交易设置里开关（默认关）、兑换比例 `points_per_unit`（多少积分抵 1 元基准币种）、上限 `points_max_percent`（占“商品金额 - 会员折扣”的百分比，运费不参与）。抵扣金额固化在订单 `points_amount`，实扣积分记流水（type=4，ref_no=订单号）；订单取消、超时关闭、商品全部退款时 `giveBack` 按流水净额退还（幂等），部分退款不退。恒等式：商品 + 运费 - 优惠 - 积分 = 应付。
- **消费得积分**：同样只经 `OrderPointsService`。交易设置里开关 `points_earn_enabled`（默认关）和 `points_earn_per_unit`（每消费 1 元基准币种送多少积分）。订单**完成**时发放（`OrderStatusService::transit` 在流转到“已完成”时调 `earn`，所以买家确认、后台确认、自动收货、虚拟商品支付即完成都覆盖），基数 = 实付 - 运费 - 已退款，折基准币种后向下取整；流水 type=3、ref_no=订单号，同一订单只发一次。完成后再退款由 `OrderRefundService::finish` 调 `clawBack`：保留积分 = 当前保留 × 退款后剩余商品款 ÷ 退款前剩余商品款，商品全部退完则扣光（流水 type=6），会员积分不够时扣到 0 为止、不会变负。结算预览返回 `earn_points`（预计获得）。
- **短信验证码**：只经 `SmsCodeService`（`send` / `verify`），用途 register / login / reset。验证码由短信平台生成并校验（驱动 `GmSmsDriver` 调平台的 verify-code 接口），商城不存验证码；本地限流：同号 60 秒一次、每天 10 次，同 IP 每小时 20 次，同号同用途错 5 次锁 10 分钟。只支持中国大陆手机号。后台启用短信驱动后，手机号注册必须带验证码（境外手机号不能注册，用邮箱），并开放短信登录和短信找回密码；未启用时手机号注册不校验。C 端用 `config/init` 的 `sms_enabled` 判断。**密钥只存 `gm_driver_config`（加密），不要写进代码、.env 示例或日志。**
- **邮箱验证码**：只经 `EmailCodeService`（`send` / `verify`），用途 register / reset。验证码由商城生成，缓存里只存 HMAC 哈希，10 分钟有效、校验通过即失效、按用途隔离；限流与防爆破规则同短信。开关是基础设置的 `email_verify_enabled`（默认关）：开启后邮箱注册必须带验证码，并开放邮箱找回密码。发信走 Laravel 邮件配置，邮件模板 `App\Mail\VerifyCodeMail`（中文语言发中文，其余发英文）。C 端用 `config/init` 的 `email_verify_enabled` 判断。
- **分销**：规则只在 `Common\DistributionService`。关系：注册时凭邀请码（`inviteCode`，会员 ID + 校验位，不占字段）绑定 `member.parent_id`，之后只能后台改（防成环）。资格：会员申请、后台审核（`gm_distributor`，同时是佣金账户），后台也可直接指定。层级 1 或 2 级：一级 = 买家的直接上级，二级 = 上级的上级；上级不是正常状态的分销员就跳过、不顺延，自购不返佣。计佣：`OrderPaidService::markPaid` 里调 `onOrderPaid` 生成待结算佣金，基数 = 商品 - 会员折扣 - 积分抵扣（不含运费）折基准币种，`(order_id, level)` 唯一保证幂等。退款：`OrderRefundService::finish` 里调 `onRefund` 按已退商品款占比重算，全部退完作废，已结算的也扣回。结算：`mall:distribution-settle`（订单完成且过售后期、无进行中售后）转为可提现并写 `gm_distribution_log`。提现：申请时可提现 → 提现中，后台审核、线下打款后登记；收款信息加密存储。配置在 `distribution` 分组（默认关闭）。**按商品设佣金**：`gm_goods.commission_type`（0 跟随全局、1 用商品自己的 `commission_rate_1/2`、2 不参与分销），层级仍只有全局开关。一张订单的佣金由 `commissionByGoods` 计算：整单基数按商品行金额占比摊开，每行乘自己的比例后加总，不参与的行不计入基数；比例取支付那一刻商品上的设置。佣金明细的 `rate` 在混合比例时是折算的综合比例（仅展示），`origin_amount` 是生成时的原始佣金，退款重算以它为准（按已退商品款占比整体缩减，不区分退的是哪件商品）。
- **数据统计**：只读，全部在 `AdminStatsService`。金额折基准币种（订单用 `base_pay_amount`，退款和商品金额按订单固化汇率折算）；支付口径按 `paid_at`、含之后退款的订单，退款金额按完成时间单独统计；日期按 `config(app.timezone)`（目前 UTC）的自然日。带表别名的原生 SQL 要自己拼表前缀（`DB::getTablePrefix()`），查询构造器不会替原生片段加前缀。趋势图用 `components/TrendChart.vue`（单序列折线，一张图一个指标，不做双轴）。
- **协议与政策**：内容存 `gm_config` 的 `protocol` 分组（多语言富文本），后台“设置 → 协议管理”维护，C 端 `GET /api/protocols`（只返回已填写的）和 `GET /api/protocols/{key}`，当前语言没填时回退默认语言。注意 `gm_config.value` 是 TEXT：一个协议所有语言合计编码后不能超过 60000 字节（`ConfigService::save` 会拦截），语言多了或协议很长时需要把该列改成 MEDIUMTEXT。
- **内容**：文章、公告、帮助中心共用 `gm_article`（`type` 1/2/3）和 `AdminArticleService`，后台三个菜单页共用 `views/content/ArticlePage.vue`；文章和帮助有一级分类（`gm_article_category`，按 type 各自一套），公告不分类。对外可见 = `status=1` 且 `published_at` 为空或已到（定时发布）。广告位 `gm_ad_position` 按编码取（预置 `home_banner`、`home_middle`、`category_top`，不能删、不能改编码），广告 `gm_ad` 的图片和标题在 `gm_ad_lang` 按语言存，缺当前语言的图时用默认语言的；跳转类型 none/goods/category/article/url。C 端接口都无需登录：`/api/articles`（`type=article|notice|help`）、`/api/articles/{id}`（浏览量 +1）、`/api/article-categories`、`/api/help`、`/api/ads?position=编码,编码`。
- **消息通知**：业务节点只调 `NotifyService::send(场景, 会员ID, 变量)`，它在事务提交后、响应返回后（`defer`）才发信，失败只写日志。场景、变量和内置中英文模板登记在 `AppSupportMessageScenes`；是否发送和自定义文案在后台“设置 → 消息模板”（存 `gm_message_template` + `_lang`，目前只有 email 渠道，默认全部关闭）。取模板顺序：会员语言 → 默认语言 → 内置模板。模板变量写 `{变量名}`，未知变量原样保留，变量值不会被二次替换。新增场景：在 MessageScenes 登记 + 在业务节点调用 send。
- **C 端（mall-uni）约定**：接口统一走 `src/utils/request.js`（自动带 `X-Lang`、`X-Currency`、`X-Source` 和令牌，401 跳登录），接口函数集中在 `src/api/index.js`；全局状态是 `src/store` 里的一个 reactive 对象（没用状态库）；界面文案在 `src/locale`（中文、英文，其他语言先回退英文），商品等业务内容的多语言由后端返回。金额、运费、区域价一律用服务端算好的结果，前端不自己算价。模板里不能直接用全局 `uni`，跳转用 `utils/page.js` 的 `nav` / `tab`。平台差异用条件编译（`#ifdef H5` 等）。订单、售后、佣金、提现的状态文字由前端按状态码翻译（`orderStatus.N` 等），不要直接显示后端的 `status_text`（只有中文）；后端返回给买家的提示已支持英文，见“C 端提示多语言”。
- **PC 端（mall-pc）约定**：和手机端用同一套 `/api` 接口，后端不区分端，只是请求头 `X-Source` 固定为 `pc`（决定可用的支付方式等）。结构照 mall-uni：`src/utils/request.js`（fetch 封装，401 跳登录并带回跳地址）、`src/api/index.js`、`src/store`（一个 reactive 对象，令牌 / 语言 / 币种存 localStorage）。**界面文案和国家列表直接引用 `mall-uni/src` 下的同一份**（Vite 别名 `@shared`），PC 端独有的文案在 `src/locale/pc.js`、用 `t('pc.xxx')`；改通用文案只改 mall-uni 那一份。`App.vue` 给 `router-view` 的 key 带上了地址、语言和币种，所以切语言 / 币种或改筛选条件时页面整个重建，页面里只需在 `onMounted` 取数。商品列表的筛选条件（分类、关键词、排序、页码）都在地址参数里。**富文本（商品详情、文章正文、协议）用 `v-html` 渲染前必须过 `utils/html.js` 的 `safeHtml`**——后端不过滤富文本。金额、运费、区域价同样只用服务端算好的结果。
- **C 端提示多语言**：业务代码里的提示一律写中文；买家语言不是中文时，`TranslateApiMessages` 中间件（只作用于 `/api/*`）在响应返回前用 `AppSupportApiMessageTranslator` 翻成英文，处理顶层 `msg`、`data.problems[].msg`、`data.reason`。**新增买家可见的提示要在 ApiMessageTranslator 登记**：固定文案加到 `EXACT`，带变量的加到 `patterns()`，表单字段名加到 `ATTRIBUTES`；`ApiMessageTranslatorTest` 会扫描买家可达的服务，漏登记会失败。没登记的提示原样返回中文。后台管理端不翻译。第三方故障（如短信平台连不上）的详情只写日志，买家只看到通用提示。
- **买家上传**：`POST /api/upload/image`（需登录；`file` + `scene`=refund/avatar；jpg/png/webp，5MB 以内；每人每天 60 张）。走 `UploadService::memberImage`，文件存到 `member/{会员ID}/{用途}/` 下（`gm_file` 没有会员字段，靠路径区分归属）。**业务接口收到买家提交的图片地址时必须调 `UploadService::assertMemberImages`**，只接受他本人上传的图片（售后凭证、头像都已接入；头像原样提交现有值时不校验，方便以后第三方登录带来的外部头像）。本地存储的图片地址前缀默认是 `APP_URL/uploads`，可用 `.env` 的 `UPLOADS_URL` 覆盖（见“本地访问方式”）。
- **物流查询**：只经 `Common\ExpressTrackService`（买家 `GET /api/orders/{订单号}/tracks`、后台 `GET /admin-api/order/ship/record/{发货记录ID}/track` 共用）。驱动类型 `express`，目前只有快递100（`Kuaidi100Driver`，实时查询接口，按次计费），后台“设置 → API 配置 → 物流查询”填 customer 和授权 key。结果缓存在发货记录上（`gm_shipping.track_status` 0 暂无 / 1 运输中 / 2 已签收 / 3 异常，`track_data` 存 `{queried_at, traces}`）：30 分钟内不重复查，已签收不再查，后台“刷新”同一包裹 5 分钟最多查一次，服务商出故障后 2 分钟内不重试。没启用、快递公司不支持（如“其它”）、服务商故障时都不报错，返回已有缓存并在 `message` 里说明。改物流单号会清掉缓存。快递公司编码在 `OrderShipService::EXPRESS`，新增快递公司要同时在驱动的 `COMPANY` 里登记对应编码。轨迹文字是快递公司的原文，不翻译。
- **驱动模式**：`DriverRegistry`（type→驱动→字段）、`DriverManager`、`DriverConfig`（`encrypted:array`）、`DriverConfigService`；后台用通用驱动配置页。
- **后台权限**：`admin.auth` 令牌（sha256 哈希、滑动过期）；路由 `perm:` 中间件（逗号 = 任一）；敏感操作单独权限点；前端 `v-perm` 和 `hasPerm`。菜单和权限点在 `MenuSeeder`。
- **C 端**：`api.token`（必须登录）/ `api.token:optional`；价格服务端算，客户端不传价；请求头 `X-Lang`、`X-Currency`、`X-Source`(h5/mp/app/pc)。
- **运费**：只经 `ShippingFeeService::calc`（结算预览和下单共用）。商品 `shipping_template_id=0` 或模板“默认包邮”则免运费；同模板商品合并按件/重量/体积套首续费；地区匹配 省/州 > 国家 > `*`，无匹配规则则拒绝下单；模板金额为基准币种，汇总后折算下单币种。
- 修改数据库结构前先向我确认。

## 前端要点
- 菜单驱动的动态路由，加载 `views/<menu.component>/index.vue`。
- 模板里不要在数据未填充时读取 `settings.x[code].y` 这类深层路径（会抛错并导致切换页面后内容区残留）。

## 已完成
管理员/角色/菜单权限、系统设置、存储驱动、商品（分类/品牌/规格/SKU/分币种定价）、AI 翻译（GPT、DeepSeek，开关式启用）、会员（等级/积分/余额）、订单核心与后台管理（发货、超时关闭、自动收货）、支付框架（Stripe、PayPal，含回调/补偿/流水页面）、C 端 API v1（注册登录、商品、地址、购物车、结算、下单、订单、支付发起/确认）、运费模板（后台管理页、商品绑定、结算预览与下单计费）、区域定价（销售区域管理、商品区域价、按收货地址取价）、售后与退款（C 端申请/撤销/退货物流接口、后台售后订单页）、下单积分抵扣、订单导出 CSV、短信与邮箱验证码、分销（邀请关系、分销员审核、佣金、结算、提现）、数据中心（后台首页与数据概览页）、协议管理（用户协议、隐私政策等 5 种，多语言）、内容（文章、公告、帮助中心、轮播图与广告位）、消息模板与邮件通知（支付成功、发货、退款完成、售后被拒、提现打款）。

## 待办（建议顺序）
1. ~~运费模板~~（已完成，见“已完成”）。
2. ~~支付宝驱动~~（已完成手机网站支付和电脑网站支付，公钥模式；**没有用真实应用联调过**；证书模式没做）。~~微信支付驱动~~（已完成小程序支付和 H5 支付；**没有用真实商户号联调过**；公众号内支付要先做公众号网页授权，扫码支付要等 PC 端，都还没做）。
3. ~~短信驱动 + 注册/登录验证码~~（已完成，对接 GM 短信平台，文档见 `docs/短信平台接入文档.md`；**还没和真实平台联调过**，场景标识待平台确认）。邮箱验证码也已完成（发信要先配 `.env` 的 `MAIL_*`，没有用真实邮箱发过）。
4. ~~退款与售后流程~~（已完成；Stripe/PayPal 真实退款还没用沙箱密钥验证，渠道退款回调已能自动完成售后单，但 Stripe / PayPal 退款事件的解析同样没有用真实报文验证过；后台可以代买家发起售后；买家申请时可上传最多 6 张凭证图片）、~~积分抵扣~~、~~订单导出~~、~~消费得积分与退款扣回~~（均已完成；积分还不影响会员等级。C 端积分明细接口 `GET /api/member/points`，不返回流水的 remark——那是给后台看的中文说明，C 端按 type 翻译）。
5. ~~物流查询驱动~~（已完成，对接快递100；**还没用真实账号联调过**，快递100 的公司编码和返回格式是按公开文档写的）、电子面单、~~分销（最多 2 级）~~（已完成，含按商品单独设比例 / 不参与分销；还不能按分类或分销员等级设比例）、~~内容~~（已完成：文章、公告、帮助中心、轮播图与广告位）、~~数据中心~~（已完成：概览、趋势、商品排行、订单分布）、应用（公众号/小程序/App）。
6. uni-app C 端（H5 / 小程序的页面已覆盖全部现有接口：首页、分类、商品详情、购物车、登录注册、短信登录、找回密码、结算下单、订单、支付发起、收货地址、售后、分销中心、公告、帮助中心、协议、个人中心、个人资料、积分明细；只做过截图检查和编译检查，没有在真机或微信开发者工具里跑过，资讯文章的入口在个人中心）、PC 端（`mall-pc`，第一批页面已完成：首页、商品列表 / 搜索、商品详情、购物车、登录注册、结算下单、订单列表与详情（含支付发起、物流轨迹）、个人中心（含收货地址管理）；2026-10-09 用无头 Edge 截图看过各页用真实数据渲染正常，**加购、下单、支付、弹窗这些点击操作没有在浏览器里实际点过**。第二批也已完成：售后（申请含凭证上传、列表、详情与填退货物流）、分销中心、资讯 / 公告 / 帮助、协议、找回密码、个人资料（含头像上传）、积分明细；会员中心各页共用 `layout/AccountLayout.vue`（左侧菜单）。同样只做过截图检查；分销中心因为测试会员不是分销员，概览 / 佣金 / 团队 / 提现四个页签没有看到过实际渲染）。
7. ~~二期 AI 翻译（Claude、Qwen）~~（已完成，**没有用真实密钥调用过**：通义千问走 OpenAI 兼容接口，和 OpenAI / DeepSeek 共用 `OpenAiCompatibleDriver`；Claude 走 Anthropic 的 Messages 接口，单独的 `ClaudeDriver`，没有强制 JSON 输出的开关，靠提示词约束 + `TranslateService` 校验重试）。~~分类/品牌/规格名的翻译按钮与批量填充~~（已完成：`LangInput` 加 `ai` 属性即有“AI 翻译”和“翻译到其他语言”按钮，分类、品牌已开；规格在 `SpecEditor` 里有“AI 翻译规格名和规格值”。都只填空着的语言。会员等级、文章 / 公告 / 帮助（标题、摘要、SEO、分类名）、基础设置也已开启。**新页面要用 AI 翻译时，记得把它的编辑权限点加到 `POST /admin-api/translate` 的路由上**，否则非超管会被拒绝。翻译提示词（`AdminTranslateService::PROMPT`）按字段名定规则：`description`、`content` 以及任何含 HTML 标签的字段按富文本处理（保留标签、整篇翻译不缩写），其他字段按短文本处理；译文的标签结构和原文不一致会重试一次，仍不一致就报错，不返回残缺的译文。**改了提示词要把 `PROMPT_VERSION` 加一**，否则 30 天内相同内容会命中旧提示词下的缓存）。

## 验证状态（重要）
最初的代码是在没有 Laravel 运行环境的沙箱里写的，只做过 `php -l` 语法检查。2026-10-09 起已在本地跑通：下面第 1、4 步完成；第 2 步除真实支付（缺 Stripe/PayPal 沙箱密钥）外都走过真实 HTTP 链路；第 3 步除纯计算的单元测试（运费、区域匹配、币种换算、退款分摊、积分抵扣）外，已有带数据库的功能测试（`home/tests/Feature/*Test.php` 中继承 `Tests\FeatureTestCase` 的五个类）：下单与库存扣减/回补、支付入账幂等与回调重放（假支付驱动，不是真实渠道）、售后退款全流程、分销佣金/结算/提现、注册登录与提示多语言。真实 Stripe/PayPal 回调验签不在测试范围内。后台页面：2026-10-09 用无头 Edge 逐页截图看过运费模板、销售区域、售后订单、分销四页、短信、交易设置、基础信息、首页、数据概览的列表态，渲染正常；弹窗、抽屉和表单提交没有在浏览器里点过。原始清单：
1. `composer install`、配置 `.env`、`php artisan migrate`，`php artisan route:list` 确认路由。
2. 逐模块冒烟测试：登录 → 商品 → 地址 → 购物车 → 下单 → 取消/超时关闭 → 支付（Stripe/PayPal 沙箱密钥）。
3. 补 PHPUnit/Pest 测试，重点：金额计算、库存扣减/回补、支付幂等与回调重放。
4. `cd admin-web && npm install && npm run build` 确认前端能构建（沙箱里没构建过）。

## 本地访问方式
- 这台开发机的浏览器走系统代理，**打不开 `https://gmmall.cc`**（命令行直连是通的）。用户的约定：一律用 `127.0.0.1` 加端口访问，不用管这个域名。
- 后台：`http://127.0.0.1:5173`（`admin-web` 的 `npm run dev`）；C 端 H5：`http://127.0.0.1:5180`（`mall-uni` 的 `npm run dev:h5`）；PC 端：`http://127.0.0.1:5190`（`mall-pc` 的 `npm run dev`）。三个开发服务器把接口（`/admin-api`、`/api`）和上传的图片（`/uploads`）转发给后端，转发目标是各自 `.env.development` 里的 `VITE_API_PROXY`（目前是 ServBay 的 `https://gmmall.cc`，这是服务器端转发，不受浏览器代理影响）。
- 本机 `home/.env` 设了 `UPLOADS_URL=/uploads`：本地存储的图片地址是不带域名的相对路径，由打开页面的开发服务器代为转发。部署到小程序 / App 需要完整地址的环境时不要用相对路径。

## 常用命令
- 测试：在 `home/` 下 `php artisan test`（单个文件：`php artisan test tests/Feature/RefundFlowTest.php`）。功能测试连独立的 MySQL 库 `gmmall_test`（`phpunit.xml` 里配置，新环境先 `CREATE DATABASE gmmall_test`），首次运行自动建表，每个测试在事务里跑完回滚；基类会拒绝在库名不以 `_test` 结尾的库上运行。新的功能测试继承 `Tests\FeatureTestCase`，用它的 `makeMember/makeAddress/makeSku/placeOrder/pay/api/adminApi`。
- 迁移：`php artisan migrate`；若旧表是手工导入 `gm_mall_step1.sql`，只跑新增迁移用 `--path=`。
- 定时任务：`mall:close-expired-orders`、`mall:auto-receive`、`mall:pay-compensate`、`mall:refund-compensate`、`mall:distribution-settle`，调度已写在 `routes/console.php`；服务器上要加一条每分钟执行 `php artisan schedule:run` 的 cron 才会生效。
- 联调命令：`mall:dev-order {member_id} {sku_id} {qty}`（造测试订单，`--paid` 模拟已付）、`mall:dev-pay {order_no} {stripe|paypal}`（发起支付，`--confirm` 确认入账）。
   ## 附件目录
   - `下载附件/` 只存放云端交付的压缩包（gm_*.zip 等）。已解压并合并进 `home/` 和 `admin-web/`，不要从这里读取代码，也不要修改或删除里面的文件。以 `home/`、`admin-web/` 里的代码为准。