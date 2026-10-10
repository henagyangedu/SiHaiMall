const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, el, desktop, mobile, pcLogin, uniGo, uniLogin } = require('../lib/harness');

/**
 * 站内信 / 消息中心（PC 端、手机端、后台）：
 * 页头的“消息”角标显示未读数 → 消息列表里新的在前 → 点“查看详情”跳到对应的订单，同时标为已读、角标减一
 * → 手机端个人中心显示剩下的未读数，从消息跳到商品 → PC 端全部已读、删除 → 后台消息模板里站内信的开关能关能开。
 * 三条消息是准备脚本通过通知服务发的（发货、到货提醒、提现打款），跑完留在测试会员的消息中心里。
 */
module.exports = async function messagesFlow(browser) {
  const flow = new Flow('messages');
  const made = backend('messages', { E2E_MSG_STEP: 'prepare' });
  if (made.count !== 3) throw new Error('准备脚本应该发出 3 条站内信，实际 ' + made.count);
  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), made.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');
  const mctx = await browser.newContext(mobile);
  const m = flow.watch(await mctx.newPage(), '手机端');
  const badge = pc.locator('.msg-link .el-badge__content');

  try {
    await flow.step('PC：登录后页头“消息”的角标是 3；消息列表里 3 条都是未读，最新的（发货）在最上面', pc, async () => {
      await pcLogin(pc);
      await badge.filter({ hasText: '3' }).waitFor();
      await pc.locator('.msg-link').click();
      await pc.waitForURL(/#\/messages$/);
      await pc.locator('.msg.unread').nth(2).waitFor();
      const first = pc.locator('.msg').first();
      await first.getByText(made.order_no).first().waitFor();
      await first.getByText('SF1234567890').waitFor();
      await pc.getByText('3 条未读').waitFor();
    });
    await flow.step('PC：点发货消息的“查看详情”进到那张订单；回到消息，这条变成已读，角标变成 2', pc, async () => {
      await pc.locator('.msg', { hasText: made.order_no }).locator('.view').click();
      await pc.waitForURL(new RegExp(`#/orders/${made.order_no}$`));
      await pc.getByText(made.order_no).first().waitFor();
      await badge.filter({ hasText: '2' }).waitFor();
      await pc.locator('.msg-link').click();
      await pc.locator('.msg', { hasText: made.order_no }).waitFor();
      if (await pc.locator('.msg.unread', { hasText: made.order_no }).count()) throw new Error('看过的消息应该是已读');
      if ((await pc.locator('.msg.unread').count()) !== 2) throw new Error('应该还剩 2 条未读');
    });
    await flow.step('手机端：个人中心的“消息”显示 2；点到货提醒的“查看详情”进商品页；回来后剩 1 条未读', m, async () => {
      await uniLogin(m);
      await uniGo(m, '/pages/user/index');
      const cell = m.locator('.cell', { hasText: '消息' }).first();
      await cell.locator('.unread', { hasText: '2' }).waitFor();
      await cell.click();
      await m.locator('.msg.unread').nth(1).waitFor();
      await m.getByText('2 条未读').waitFor();
      await m.locator('.msg', { hasText: '到货' }).locator('.view').click();
      await m.waitForURL(new RegExp(`pages/goods/detail\\?id=${made.goods_id}`));
      await uniGo(m, '/pages/user/messages');
      await m.getByText('1 条未读').waitFor();
      if ((await m.locator('.msg.unread').count()) !== 1) throw new Error('应该还剩 1 条未读');
    });
    await flow.step('PC：全部已读后角标消失；删掉一条，列表剩 2 条，刷新后还是 2 条', pc, async () => {
      await pc.reload();
      await badge.filter({ hasText: '1' }).waitFor();
      await pc.getByRole('button', { name: '全部已读' }).click();
      await pc.locator('.msg.unread').first().waitFor({ state: 'detached' });
      await badge.waitFor({ state: 'hidden' });
      await pc.locator('.msg', { hasText: '提现' }).locator('.remove').click();
      await pc.locator('.msg', { hasText: '提现' }).waitFor({ state: 'detached' });
      await pc.reload();
      await pc.locator('.msg').nth(1).waitFor();
      if ((await pc.locator('.msg').count()) !== 2) throw new Error('删掉一条后应该剩 2 条');
    });
    await flow.step('后台：消息模板里每个场景有站内信和邮件两个开关，站内信默认是开的，可以关掉再打开', admin, async () => {
      await admin.goto(config.admin + '/setting/message');
      const row = el.row(admin, '分销提现已打款');
      const site = row.locator('.el-switch').first();
      await site.waitFor();
      if (!(await site.evaluate((e) => e.classList.contains('is-checked')))) throw new Error('站内信开关应该是开着的');
      if ((await row.locator('.el-switch').count()) !== 2) throw new Error('每个场景应该有站内信和邮件两个开关');
      await site.click();
      await el.message(admin, '已关闭「分销提现已打款」站内信');
      await admin.reload();
      const again = el.row(admin, '分销提现已打款').locator('.el-switch').first();
      await again.waitFor();
      if (await again.evaluate((e) => e.classList.contains('is-checked'))) throw new Error('关掉后刷新，站内信开关应该是关的');
      await again.click();
      await el.message(admin, '已开启「分销提现已打款」站内信');
    });
  } finally {
    await pc.context().close();
    await adminCtx.close();
    await mctx.close();
    backend('messages', { E2E_MSG_STEP: 'restore', E2E_MSG_SITE: made.site_was });
  }

  return flow;
};
