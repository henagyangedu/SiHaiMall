const fs = require('fs');
const os = require('os');
const path = require('path');
const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, el, desktop, mobile, pcLogin, uniGo, uniLogin } = require('../lib/harness');

/**
 * 商品评价，跨 PC 端、手机端、后台：
 * PC 端评价一张已完成的订单（带图、匿名）→ 手机端评价另一张 → 两端商品页都能看到 → 后台回复 → 后台隐藏。
 * 每跑一遍造两张已完成订单（占 2 件库存），留下的两条评价在最后都被隐藏，不会堆在商品页上。
 */
module.exports = async function reviewsFlow(browser) {
  const flow = new Flow('reviews');
  const made = backend('reviews');
  const [orderA, orderB] = made.orders;
  const stamp = Date.now().toString().slice(-6);
  const textA = `点击测试评价 PC ${stamp}`;
  const textB = `点击测试评价 手机 ${stamp}`;
  const replyText = `感谢支持 ${stamp}`;
  // 匿名评价只显示昵称的第一个字
  const masked = made.nickname.slice(0, 1) + '***';

  const image = path.join(os.tmpdir(), 'gm-e2e-review.png');
  fs.writeFileSync(image, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64'));

  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), made.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');
  const mctx = await browser.newContext(mobile);
  const m = flow.watch(await mctx.newPage(), '手机端');

  const pcGoods = async () => { await pc.goto(`${config.pc}/#/goods/${made.goods_id}`); await pc.reload(); await pc.locator('.reviews').waitFor(); };
  const pcReview = (text) => pc.locator('.reviews .rv', { hasText: text });
  // 后台：在商品评价页按订单号找到那条评价
  const adminRow = async (orderNo) => {
    await admin.goto(config.admin + '/goods/review');
    await admin.waitForLoadState('networkidle').catch(() => {});
    await admin.getByPlaceholder('评价内容 / 商品名称 / 订单号').fill(orderNo);
    await admin.getByPlaceholder('评价内容 / 商品名称 / 订单号').press('Enter');
    const row = el.row(admin, orderNo);
    await row.waitFor();
    return row;
  };

  try {
    await flow.step('PC：已完成订单的商品行有“评价”按钮，写一条 4 星、带图的匿名评价', pc, async () => {
      await pcLogin(pc);
      // 订单列表的“待评价”页签里有这两张刚完成的订单，从那里进详情
      await pc.goto(`${config.pc}/#/orders`);
      await pc.getByRole('tab', { name: '待评价' }).click();
      await pc.locator('.order', { hasText: orderB }).waitFor();
      await pc.locator('.order', { hasText: orderA }).getByRole('button', { name: '查看详情' }).click();
      await pc.waitForURL(new RegExp(orderA));
      await pc.locator('.item').getByRole('button', { name: '评价' }).click();
      const d = el.dialog(pc);
      await d.locator('.el-rate__item').nth(3).click();
      await d.getByText('满意', { exact: true }).waitFor();
      await d.locator('textarea').fill(textA);
      await d.locator('input[type=file]').setInputFiles(image);
      await d.locator('.pic-img').waitFor();
      await d.locator('.el-checkbox', { hasText: '匿名评价' }).click();
      await d.getByRole('button', { name: '提交评价' }).click();
      await el.message(pc, '评价成功');
      // 评过之后按钮变成“已评价”，不能再评
      await pc.locator('.item .rv', { hasText: '已评价' }).waitFor();
      if (await pc.locator('.item').getByRole('button', { name: '评价' }).count()) throw new Error('评价后还显示评价按钮');
    });
    await flow.step('PC：商品页的评价区显示这条评价，昵称打码、有图、4 星', pc, async () => {
      await pcGoods();
      const r = pcReview(textA);
      await r.waitFor();
      await r.locator('.name', { hasText: masked }).waitFor();
      if ((await r.locator('.name').textContent()).trim() !== masked) throw new Error('匿名评价的昵称没有打码');
      if (await r.locator('img.avatar').count()) throw new Error('匿名评价不应该显示头像');
      await r.locator('.pic').first().waitFor();
      if ((await r.locator('.el-rate__item .is-active, .el-rate__icon.is-active').count()) < 4) throw new Error('星级显示不对');
      // 筛选：这条是好评、有图；点“差评”后看不到它
      await pc.locator('.reviews .level', { hasText: '有图' }).click();
      await pcReview(textA).waitFor();
      await pc.locator('.reviews .level', { hasText: '差评' }).click();
      await pcReview(textA).waitFor({ state: 'detached' });
    });

    await flow.step('手机端：订单详情点“评价”，写一条 2 星评价，回到订单后显示“已评价”', m, async () => {
      await uniLogin(m);
      await uniGo(m, `/pages/order/detail?order_no=${orderB}`);
      await m.locator('.rv-btn', { hasText: '评价' }).click();
      await m.locator('.star').nth(1).waitFor();
      await m.locator('.star').nth(1).click();
      await m.getByText('较差', { exact: true }).waitFor();
      await m.locator('uni-textarea textarea').fill(textB);
      await m.locator('.btn.submit').click();
      await m.locator('uni-toast', { hasText: '评价成功' }).waitFor();
      await m.locator('.rv-done', { hasText: '已评价' }).waitFor();
    });
    await flow.step('手机端：商品页带出最新评价和好评率，评价列表页能按差评筛出刚才那条', m, async () => {
      await uniGo(m, `/pages/goods/detail?id=${made.goods_id}`);
      await m.locator('.reviews .rv-head', { hasText: '好评率' }).waitFor();
      await m.locator('.reviews .rv', { hasText: textB }).waitFor();
      await m.locator('.reviews .rv-head').click();
      await m.locator('.level', { hasText: '差评' }).click();
      await m.locator('.rv', { hasText: textB }).waitFor();
      if (await m.locator('.rv', { hasText: textA }).count()) throw new Error('差评筛选里出现了好评');
      await m.locator('.rv', { hasText: textB }).getByText(made.nickname, { exact: true }).waitFor();
    });

    await flow.step('后台：商品评价里按订单号找到 PC 那条，回复它', admin, async () => {
      const row = await adminRow(orderA);
      await row.getByText(textA).waitFor();
      await row.locator('.el-tag', { hasText: '匿名' }).waitFor();
      await row.getByRole('button', { name: '回复' }).click();
      const d = el.dialog(admin);
      await d.locator('textarea').fill(replyText);
      await d.getByRole('button', { name: '保存' }).click();
      await el.message(admin, '已回复');
      await el.row(admin, orderA).getByText(replyText).waitFor();
    });
    await flow.step('PC：商品页上这条评价下面出现商家回复', pc, async () => {
      await pcGoods();
      await pcReview(textA).locator('.reply', { hasText: replyText }).waitFor();
    });
    await flow.step('后台：隐藏两条评价后，商品页不再显示它们', admin, async () => {
      for (const orderNo of [orderA, orderB]) {
        const row = await adminRow(orderNo);
        await row.getByRole('button', { name: '隐藏' }).click();
        await el.boxOk(admin);
        await el.message(admin, '已隐藏');
        await el.row(admin, orderNo).locator('.el-tag', { hasText: '已隐藏' }).waitFor();
      }
      await pcGoods();
      if (await pcReview(textA).count() || await pcReview(textB).count()) throw new Error('隐藏后商品页还能看到评价');
      // 买家自己的订单里仍然是“已评价”，不能重新评
      await pc.goto(`${config.pc}/#/orders/${orderB}`);
      await pc.locator('.item .rv', { hasText: '已评价' }).waitFor();
      // 两张都评过了，不再出现在“待评价”里
      await pc.goto(`${config.pc}/#/orders?tab=to_review`);
      await pc.getByRole('tab', { name: '待评价', selected: true }).waitFor();
      await pc.waitForLoadState('networkidle').catch(() => {});
      if (await pc.locator('.order', { hasText: orderA }).count() || await pc.locator('.order', { hasText: orderB }).count()) throw new Error('评过的订单还在待评价里');
    });
  } finally {
    await pc.context().close();
    await adminCtx.close();
    await mctx.close();
  }

  return flow;
};
