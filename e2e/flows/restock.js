const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, el, desktop, mobile, pcLogin, uniGo, uniLogin } = require('../lib/harness');

/**
 * 到货提醒（PC 端、手机端、后台）：
 * 缺货商品的详情页没有购买按钮，只有“到货提醒我” → 订阅后在会员中心的“到货提醒”里是“等待到货” → 后台商品列表显示有人在等
 * → 手机端看到同一条订阅，可以取消再订阅 → 补货并执行到货通知后，列表里变成“已到货”，点“去购买”回到详情页，购买按钮回来了。
 * 用的是一个专门的示例商品（货号 E2E-RESTOCK）；结束时把它改回缺货并留一条订阅，作为开发库里的例子。
 */
module.exports = async function restockFlow(browser) {
  const flow = new Flow('restock');
  const step = (name) => backend('restock', { E2E_RESTOCK_STEP: name });
  const made = step('prepare');
  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), made.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');
  const mctx = await browser.newContext(mobile);
  const m = flow.watch(await mctx.newPage(), '手机端');
  const item = () => pc.locator('.item', { hasText: made.title });

  try {
    await flow.step('PC：缺货商品没有购买按钮，点“到货提醒我”后变成“取消提醒”，刷新还在', pc, async () => {
      await pcLogin(pc);
      await pc.goto(`${config.pc}/#/goods/${made.goods_id}`);
      await pc.getByRole('button', { name: '到货提醒我' }).waitFor();
      await pc.getByText('这个规格暂时缺货').waitFor();
      if (await pc.getByRole('button', { name: '立即购买' }).count()) throw new Error('缺货时不应该有“立即购买”');
      await pc.getByRole('button', { name: '到货提醒我' }).click();
      await el.message(pc, '到货后会通知你');
      await pc.getByRole('button', { name: '取消提醒' }).waitFor();
      await pc.reload();
      await pc.getByRole('button', { name: '取消提醒' }).waitFor();
    });
    await flow.step('PC：会员中心的“到货提醒”里有这个商品，状态是等待到货', pc, async () => {
      await pc.goto(config.pc + '/#/user');
      await pc.locator('.menu .item', { hasText: '到货提醒' }).click();
      await pc.waitForURL(/#\/restock$/);
      await item().locator('.state.waiting', { hasText: '等待到货' }).waitFor();
      if (await item().getByRole('button', { name: '去购买' }).count()) throw new Error('还没到货不应该有“去购买”');
    });
    await flow.step('后台：商品列表里这个商品显示“1 人等到货”', admin, async () => {
      await admin.goto(config.admin + '/goods/list');
      await admin.waitForLoadState('networkidle').catch(() => {});
      await admin.getByPlaceholder('标题 / 商品编码 / 规格编码').fill('E2E-RESTOCK');
      await admin.getByPlaceholder('标题 / 商品编码 / 规格编码').press('Enter');
      await el.row(admin, made.title).locator('.waiting', { hasText: '1 人等到货' }).waitFor();
    });
    await flow.step('手机端：详情页显示已订阅，取消后变回“到货提醒我”，再订阅；个人中心的“到货提醒”里有它', m, async () => {
      await uniLogin(m);
      await uniGo(m, `/pages/goods/detail?id=${made.goods_id}`);
      const btn = m.locator('.bar .btn.restock');
      await m.locator('.bar .btn.restock', { hasText: '取消提醒' }).waitFor();
      if (await m.locator('.bar .btn', { hasText: '立即购买' }).count()) throw new Error('缺货时不应该有“立即购买”');
      await btn.click();
      await m.locator('.bar .btn.restock', { hasText: '到货提醒我' }).waitFor();
      await btn.click();
      await m.locator('.bar .btn.restock', { hasText: '取消提醒' }).waitFor();
      await uniGo(m, '/pages/user/index');
      await m.locator('.cell', { hasText: '到货提醒' }).first().click();
      await m.locator('.card', { hasText: made.title }).locator('.state.waiting', { hasText: '等待到货' }).waitFor();
    });
    await flow.step('补货并执行到货通知后：PC 列表里变成“已到货”，点“去购买”回到详情页，可以购买了', pc, async () => {
      const r = step('arrive');
      if (r.done !== 1) throw new Error('到货通知应该处理 1 条订阅，实际 ' + r.done);
      await pc.reload();
      await item().locator('.state.arrived', { hasText: '已到货' }).waitFor();
      await item().getByRole('button', { name: '去购买' }).click();
      await pc.waitForURL(new RegExp(`#/goods/${made.goods_id}$`));
      await pc.getByRole('button', { name: '立即购买' }).waitFor();
      if (await pc.getByRole('button', { name: '到货提醒我' }).count()) throw new Error('有货了不应该再显示“到货提醒我”');
    });
    await flow.step('PC：已到货的那条可以移除；手机端列表同步变空', pc, async () => {
      await pc.goto(config.pc + '/#/restock');
      await item().locator('.remove', { hasText: '移除' }).click();
      await item().waitFor({ state: 'detached' });
      await uniGo(m, '/pages/user/restock');
      await m.locator('.empty', { hasText: '还没有订阅到货提醒' }).waitFor();
    });
  } finally {
    await pc.context().close();
    await adminCtx.close();
    await mctx.close();
    step('leave');
  }

  return flow;
};
