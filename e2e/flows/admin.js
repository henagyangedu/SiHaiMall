const config = require('../lib/config');
const { Flow, Skip, el } = require('../lib/harness');

/** 后台：订单详情与物流、代买家发起售后并退款、接口配置、商品佣金设置、交易设置、AI 翻译按钮 */
module.exports = async function adminFlow(browser, data) {
  const flow = new Flow('admin');
  const ctx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  // 用准备步骤发的临时管理员令牌登录，不需要知道管理员密码
  await ctx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), data.admin_token);
  const page = flow.watch(await ctx.newPage(), '后台');
  const go = async (p) => { await page.goto(config.admin + p); await page.waitForLoadState('networkidle').catch(() => {}); };
  const step = (name, fn) => flow.step(name, page, fn);
  const searchOrder = async (no) => {
    await go('/order/list');
    await page.getByPlaceholder('订单号').fill(no);
    await page.getByPlaceholder('订单号').press('Enter');
    await el.row(page, no).waitFor();
  };

  await step('首页和数据概览能打开', async () => {
    await go('/dashboard');
    await page.locator('.el-menu').first().waitFor();
    await go('/data/overview');
    await page.locator('svg').first().waitFor();
  });

  await step('订单列表：按订单号搜索并打开详情抽屉', async () => {
    await searchOrder(data.shipped_order_no);
    await el.row(page, data.shipped_order_no).getByRole('button', { name: '详情' }).click();
    await el.drawer(page).getByText('操作日志').waitFor();
  });
  await step('订单详情：查看物流弹窗', async () => {
    await el.drawer(page).getByRole('button', { name: '查看物流' }).first().click();
    const d = el.dialog(page);
    await d.locator('.el-tag').first().waitFor();
    const text = await d.textContent();
    if (!/没有开通|暂时|不支持|暂无|运输中|已签收|异常/.test(text)) throw new Error('弹窗里没有状态或说明：' + text.slice(0, 80));
    await d.locator('.el-dialog__headerbtn').click();
    await page.keyboard.press('Escape');
  });

  await step('订单详情：打印小票（生成的小票里有订单号和商品）', async () => {
    await searchOrder(data.shipped_order_no);
    await el.row(page, data.shipped_order_no).getByRole('button', { name: '详情' }).click();
    await el.drawer(page).getByRole('button', { name: '打印小票' }).click();
    // 小票放在一个隐藏的 iframe 里交给浏览器打印；这里检查它的内容，不检查打印机
    const frame = page.locator('#gm-print-frame');
    await frame.waitFor({ state: 'attached' });
    const html = await frame.getAttribute('srcdoc');
    if (!html.includes(data.shipped_order_no)) throw new Error('小票里没有订单号');
    if (!html.includes(data.shipped_item_title)) throw new Error('小票里没有商品名');
    await page.keyboard.press('Escape');
  });
  await step('小票配置：页面有预览，改抬头后预览跟着变（不保存）', async () => {
    await go('/setting/receipt');
    const title = page.locator('.el-form-item', { hasText: '小票抬头' }).locator('input');
    await title.waitFor();
    await title.fill('点击测试抬头');
    await page.waitForFunction(() => (document.querySelector('iframe.paper')?.getAttribute('srcdoc') || '').includes('点击测试抬头'));
  });

  let refundNo = '';
  await step('订单列表：代买家发起整单售后', async () => {
    await searchOrder(data.paid_order_no);
    await el.row(page, data.paid_order_no).getByRole('button', { name: '售后' }).click();
    const d = el.dialog(page);
    await d.locator('.el-radio', { hasText: '整单退款' }).click();
    await d.locator('textarea').fill('点击测试：协商退款');
    await d.getByRole('button', { name: '提交' }).click();
    const msg = page.locator('.el-message', { hasText: '已创建' }).first();
    await msg.waitFor();
    refundNo = ((await msg.textContent()).match(/RF\d+/) || [''])[0];
    if (!refundNo) throw new Error('提示里没有售后单号');
  });
  await step('售后订单：打开这张单并登记线下已退款', async () => {
    await go('/order/refund');
    await page.getByPlaceholder(/单号/).first().fill(refundNo);
    await page.getByPlaceholder(/单号/).first().press('Enter');
    await el.row(page, refundNo).getByRole('button', { name: '处理' }).click();
    await el.drawer(page).getByText('商家发起：点击测试：协商退款').waitFor();
    await el.drawer(page).getByRole('button', { name: '线下已退款' }).click();
    await el.boxOk(page);
    await el.message(page, '退款已完成');
  });
  await step('退款后订单变为已关闭，售后入口消失', async () => {
    await searchOrder(data.paid_order_no);
    await el.row(page, data.paid_order_no).getByText('已关闭').waitFor();
    if (await el.row(page, data.paid_order_no).getByRole('button', { name: '售后' }).count()) throw new Error('已关闭的订单还显示售后按钮');
  });

  await step('商城支付：列出支付宝和微信支付，微信的私钥和公钥是多行输入框', async () => {
    await go('/setting/api/pay');
    await el.row(page, '支付宝').waitFor();
    await el.row(page, '微信支付').getByRole('button', { name: '配置' }).click();
    const d = el.dialog(page);
    await d.locator('.el-form-item', { hasText: '商户私钥' }).locator('textarea').waitFor();
    await d.locator('.el-form-item', { hasText: '微信支付公钥' }).locator('textarea').last().waitFor();
    await d.getByRole('button', { name: '取消' }).click();
  });
  await step('物流查询：能保存快递100 配置，密钥不回显', async () => {
    await go('/setting/api/express');
    const r = el.row(page, '快递100');
    await r.waitFor();
    // 已经填过真实账号就不动它，免得把真实配置覆盖成测试值
    if (await r.getByText('已配置').count()) throw new Skip('快递100 已有配置，不覆盖');
    await r.getByRole('button', { name: '配置' }).click();
    let d = el.dialog(page);
    await d.locator('.el-form-item', { hasText: 'customer' }).locator('input').fill('E2E-CUSTOMER');
    await d.locator('.el-form-item', { hasText: '授权 key' }).locator('input').fill('E2E-SECRET-KEY');
    await d.getByRole('button', { name: '保存' }).click();
    await el.message(page, '保存成功');
    await el.row(page, '快递100').getByText('已配置').waitFor();
    await el.row(page, '快递100').getByRole('button', { name: '配置' }).click();
    d = el.dialog(page);
    const shown = await d.locator('.el-form-item', { hasText: '授权 key' }).locator('input').inputValue();
    if (shown.includes('E2E-SECRET-KEY')) throw new Error('密钥被原样回显了');
    await d.getByRole('button', { name: '取消' }).click();
  });

  await step('应用 → 小程序：侧边菜单能进入，能保存配置，AppSecret 不回显', async () => {
    await go('/dashboard');
    await page.locator('.el-sub-menu__title', { hasText: '应用' }).click();
    await page.locator('.el-menu-item', { hasText: '小程序' }).click();
    await page.waitForURL(/\/app\/mini$/);
    const r = el.row(page, '微信小程序');
    await r.waitFor();
    // 已经填过真实的小程序就不动它
    if (await r.getByText('已配置').count()) throw new Skip('微信小程序已有配置，不覆盖');
    await r.getByRole('button', { name: '配置' }).click();
    let d = el.dialog(page);
    await d.locator('.el-form-item', { hasText: '小程序 AppID' }).locator('input').fill('wxe2e0000000000000');
    await d.locator('.el-form-item', { hasText: '小程序 AppSecret' }).locator('input').fill('E2E-APP-SECRET');
    await d.getByRole('button', { name: '保存' }).click();
    await el.message(page, '保存成功');
    await el.row(page, '微信小程序').getByText('已配置').waitFor();
    await el.row(page, '微信小程序').getByRole('button', { name: '配置' }).click();
    d = el.dialog(page);
    const shown = await d.locator('.el-form-item', { hasText: '小程序 AppSecret' }).locator('input').inputValue();
    if (shown.includes('E2E-APP-SECRET')) throw new Error('AppSecret 被原样回显了');
    await d.getByRole('button', { name: '取消' }).click();
  });

  // 测试建的券名字都以“点击测试券”开头，结束时由 cleanup.php 连同发出去的券一起删掉
  const couponName = '点击测试券 ' + Date.now().toString().slice(-6);
  await step('营销 → 优惠券：新建一张满 300 减 30 的券，不填名称会被拦住', async () => {
    await go('/dashboard');
    await page.locator('.el-sub-menu__title', { hasText: '营销' }).click();
    await page.locator('.el-menu-item', { hasText: '优惠券' }).click();
    await page.waitForURL(/\/marketing\/coupon$/);
    await page.getByRole('button', { name: '新建优惠券' }).click();
    const d = el.dialog(page);
    const num = (label) => d.locator('.el-form-item', { hasText: label }).locator('.el-input-number input').first();
    await num('使用门槛').fill('300');
    await num('满减金额').fill('30');
    await d.locator('.el-radio', { hasText: '领取后若干天' }).click();
    await d.getByRole('button', { name: '保存' }).click();
    await el.message(page, '请填写券名称');
    await d.getByPlaceholder(/买家能看到/).fill(couponName);
    await d.getByRole('button', { name: '保存' }).click();
    await el.message(page, '保存成功');
    const row = el.row(page, couponName);
    await row.getByText('满 300 减 30').waitFor();
    await row.getByText('领取后 7 天内').waitFor();
  });
  await step('优惠券：发放给测试会员，找不到的会员会被拦住，记录里能看到', async () => {
    await el.row(page, couponName).getByRole('button', { name: '发放' }).click();
    let d = el.dialog(page);
    await d.locator('textarea').fill('nobody-e2e@example.com');
    await d.getByRole('button', { name: '发放' }).click();
    await el.message(page, '找不到这些会员');
    await d.locator('textarea').fill(config.account);
    await d.getByRole('button', { name: '发放' }).click();
    await el.message(page, '已发放给 1 位会员');
    await el.row(page, couponName).getByText('不限 / 1 / 0').waitFor();
    await el.row(page, couponName).getByRole('button', { name: '记录' }).click();
    const drawer = el.drawer(page);
    const rec = drawer.locator('.el-table__row', { hasText: data.nickname }).first();
    await rec.getByText('后台发放').waitFor();
    await rec.getByText('未使用').waitFor();
    await page.keyboard.press('Escape');
  });
  await step('优惠券：有人领过之后不能删除、面额被锁定，可以停用', async () => {
    const row = () => el.row(page, couponName);
    if (!(await row().getByRole('button', { name: '删除' }).isDisabled())) throw new Error('有人领过的券不应该能删除');
    await row().getByRole('button', { name: '编辑' }).click();
    const d = el.dialog(page);
    await d.getByText('面额、门槛、适用范围和有效期不能再改').waitFor();
    if (!(await d.locator('.el-form-item', { hasText: '满减金额' }).locator('.el-input-number input').isDisabled())) throw new Error('满减金额应该被锁定');
    await d.getByRole('button', { name: '取消' }).click();
    await row().getByRole('button', { name: '停用' }).click();
    await el.boxOk(page);
    await el.message(page, '已停用');
    await row().locator('.el-tag', { hasText: '停用' }).waitFor();
    if (!(await row().getByRole('button', { name: '发放' }).isDisabled())) throw new Error('停用的券不应该还能发放');
  });

  await step('商品编辑：单独设置佣金比例并保存，重新打开仍在，再改回原样', async () => {
    await go('/goods/list');
    // 后台按默认语言显示商品名，和测试会员当前用什么语言无关。商品多了不一定在第一页，先按名称搜
    await page.getByPlaceholder('标题 / 商品编码 / 规格编码').fill(data.single.title_default);
    await page.getByPlaceholder('标题 / 商品编码 / 规格编码').press('Enter');
    const r = () => el.row(page, data.single.title_default);
    await r().getByRole('button', { name: '编辑' }).click();
    let d = el.drawer(page);
    await d.locator('.el-radio', { hasText: '跟随全局比例' }).waitFor();
    const original = (await d.locator('.el-radio.is-checked').filter({ hasText: /跟随全局比例|单独设置|不参与分销/ }).first().textContent()).trim();
    if (original !== '跟随全局比例') throw new Skip(`这个商品已经设成“${original}”，不改动`);
    await d.locator('.el-radio', { hasText: '单独设置' }).click();
    const item = d.locator('.el-form-item', { hasText: '佣金比例' });
    await item.locator('input').first().fill('12');
    await item.locator('input').nth(1).fill('3');
    await d.getByRole('button', { name: '保存' }).click();
    await el.message(page, '保存成功');
    await r().getByRole('button', { name: '编辑' }).click();
    d = el.drawer(page);
    await d.locator('.el-radio.is-checked', { hasText: '单独设置' }).waitFor();
    const v = await d.locator('.el-form-item', { hasText: '佣金比例' }).locator('input').first().inputValue();
    if (Number(v) !== 12) throw new Error('一级比例没保存上，现在是 ' + v);
    await d.locator('.el-radio', { hasText: '跟随全局比例' }).click();
    await d.getByRole('button', { name: '保存' }).click();
    await el.message(page, '保存成功');
  });

  await step('交易设置：“消费得积分”开关能展开或收起赠送比例（不保存）', async () => {
    await go('/setting/trade');
    const sw = page.locator('.el-form-item', { hasText: '消费得积分' }).first().locator('.el-switch');
    const ratio = page.locator('.el-form-item', { hasText: '赠送比例' });
    const wasOn = (await ratio.count()) > 0;
    await sw.click();
    if (wasOn) await ratio.waitFor({ state: 'detached' });
    else await ratio.waitFor();
  });
  await step('分类、会员等级的名称输入框有 AI 翻译按钮', async () => {
    for (const p of ['/goods/category', '/member/level']) {
      await go(p);
      await page.locator('.el-table__row').first().getByRole('button', { name: '编辑' }).click();
      await el.dialog(page).getByRole('button', { name: '翻译到其他语言' }).waitFor();
      await el.dialog(page).getByRole('button', { name: '取消' }).click();
    }
  });

  await ctx.close();
  return flow;
};
