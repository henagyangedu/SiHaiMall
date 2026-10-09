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

  await step('商品编辑：单独设置佣金比例并保存，重新打开仍在，再改回原样', async () => {
    await go('/goods/list');
    // 后台按默认语言显示商品名，和测试会员当前用什么语言无关
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
