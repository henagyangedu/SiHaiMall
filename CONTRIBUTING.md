# 参与开发

欢迎提 Issue 和 Pull Request。

## 提 Issue

- **报告问题**：写清楚怎么重现、期望的结果和实际的结果、用的是哪个版本（或哪次提交）。
- **功能建议**：先说要解决什么问题，再说你设想的做法。
- **安全问题**：不要公开提 Issue，见 [SECURITY.md](SECURITY.md)。
- **商业授权**：见 [docs/授权说明.md](docs/授权说明.md)。

## 提交代码之前

1. 阅读并同意[贡献者协议](docs/贡献者协议.md)，在 Pull Request 的说明里勾选。
2. 读一下 [CLAUDE.md](CLAUDE.md) 里和你要改的模块有关的章节。那里记着业务规则和设计取舍，很多看起来“可以简化”的地方是有原因的。
3. 较大的改动先开一个 Issue 讨论方案，免得白做。

## 代码约定

**后端（`home/`）**

- 分层：中间件 → 控制器 → 表单请求 → 服务 → 模型。控制器里不直接查数据库。
- 金额只用字符串和 bcmath（经 `App\Support\Money`），不用浮点数。
- 订单状态只经 `OrderStatusService::transit` 修改；“已支付”只经 `OrderPaidService::markPaid`。
- 读路由参数用 `$request->route('name')`。
- 新增后台接口要挂权限点，并在 `MenuSeeder` 里登记。
- 新增买家可见的提示，要在 `ApiMessageTranslator` 和 `app/Support/ApiMessages/` 下的五个语言文件里登记。
- **改数据库结构只加新的迁移文件，不要改已有的迁移**，这样别人才能从旧版本升级上来。
- 不要对 `app/` 下已有的文件整体跑格式化工具；`tests/` 下的文件用 Pint 格式化。
- 注释和提交说明用中文。

**前端**

- 买家端的界面文字都放在 `mall-uni/src/locale/`（PC 端独有的在 `mall-pc/src/locale/pc.js`），七种语言都要补。
- 价格、运费、税费一律用后端算好的结果，前端不自己算。
- PC 端用 `v-html` 渲染富文本前要过 `safeHtml`。

## 提交之前要通过的检查

```bash
cd home && php artisan test                 # 后端测试
cd mall-uni && npm run check:locale         # 界面文案（改了文案时）
cd admin-web && npm run build               # 改了哪个前端就构建哪个
cd mall-pc && npm run build
cd mall-uni && npm run build:h5 && npm run build:mp-weixin
```

改了页面的交互，最好再跑一下相关的浏览器点击测试（`e2e/`，用法见 `CLAUDE.md` 的“常用命令”）。

提交 Pull Request 后，上面前三类检查会自动再跑一遍。

## 提交说明

一句话说清楚做了什么，需要的话空一行再写原因和要注意的地方。例如：

```
优惠券：其他币种的订单按汇率换算券面金额

原来券面金额只按人民币算，美元订单用券时减的数额不对。
```
