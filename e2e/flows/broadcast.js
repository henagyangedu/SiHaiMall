const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, el, desktop, mobile, pcLogin, uniGo, uniLogin } = require('../lib/harness');

/**
 * 后台群发消息（后台、PC 端、手机端）：
 * 后台新建一条只发给测试会员的群发消息（不填标题、会员找不到都会被拦住）→ 列表里是待发送 → 点“立即发送”变成已完成、已发 1 人
 * → PC 端消息中心最上面就是它，点“查看详情”进领券中心 → 手机端也能看到 → 后台再建一条，撤回后不能再发。
 * 两条都只发给测试会员一个人，不会打扰开发库里的其他会员；发出去的那条留在他的消息中心里当例子。
 */
module.exports = async function broadcastFlow(browser) {
  const flow = new Flow('broadcast');
  const made = backend('broadcast');
  const stamp = new Date().toTimeString().slice(0, 8);
  const title = `会员日：全场满 200 减 20（${stamp}）`;
  const revoked = `撤回示例（${stamp}）`;
  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), made.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');
  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const mctx = await browser.newContext(mobile);
  const m = flow.watch(await mctx.newPage(), '手机端');

  // 打开新建弹窗，填标题、正文，选“指定会员”
  async function fillForm(d, t, members) {
    await d.locator('.el-form-item', { hasText: '正文' }).locator('textarea:visible').fill('本周六、周日两天，全场满 200 减 20，先到领券中心领券。');
    if (t) await d.locator('.el-form-item', { hasText: '标题' }).first().locator('input:visible').first().fill(t);
    await d.getByText('指定会员', { exact: true }).click();
    await d.locator('.el-form-item', { hasText: '发给谁' }).locator('textarea').fill(members);
  }

  try {
    await flow.step('后台：新建群发消息，不填标题、会员找不到都会被拦住；填好后列表里出现一条“待发送”', admin, async () => {
      await admin.goto(config.admin + '/member/broadcast');
      await admin.getByRole('button', { name: '新建群发消息' }).click();
      const d = el.dialog(admin);
      await fillForm(d, '', config.account);
      await d.getByRole('button', { name: '提交发送' }).click();
      await el.message(admin, '请填写默认语言');
      await fillForm(d, title, 'nobody-e2e@example.com');
      await d.getByRole('button', { name: '提交发送' }).click();
      await el.message(admin, '找不到这些会员');
      await fillForm(d, title, config.account);
      // 跳转选“领券中心”
      await d.locator('.el-form-item', { hasText: '点了跳去哪' }).locator('.el-select').click();
      await el.option(admin, '领券中心').click();
      await d.getByRole('button', { name: '提交发送' }).click();
      await el.message(admin, '已提交');
      const row = el.row(admin, title);
      await row.getByText('待发送').waitFor();
      await row.getByText('1 位指定会员').waitFor();
      await row.getByText('领券中心').waitFor();
    });
    await flow.step('后台：点“立即发送”，状态变成已完成、已发 1 人，操作按钮消失；点标题能看到内容', admin, async () => {
      const row = el.row(admin, title);
      await row.getByRole('button', { name: '立即发送', exact: true }).click();
      await el.message(admin, '已发送给 1 位会员');
      await row.getByText('已完成').waitFor();
      if (await row.getByRole('button', { name: '撤回', exact: true }).count()) throw new Error('发完的消息不应该还能撤回');
      await row.getByText(title).click();
      await el.dialog(admin).getByText('先到领券中心领券').waitFor();
      await admin.keyboard.press('Escape');
    });
    await flow.step('PC：消息中心最上面是这条群发消息、未读；点“查看详情”进领券中心', pc, async () => {
      await pcLogin(pc);
      await pc.locator('.msg-link').click();
      await pc.waitForURL(/#\/messages$/);
      const first = pc.locator('.msg').first();
      await first.getByText(title).waitFor();
      await first.getByText('先到领券中心领券').waitFor();
      if (!(await first.evaluate((e) => e.classList.contains('unread')))) throw new Error('新收到的群发消息应该是未读');
      await first.locator('.view').click();
      await pc.waitForURL(/#\/coupons$/);
    });
    await flow.step('手机端：消息里也有这条，已经是已读（刚在 PC 端看过）', m, async () => {
      await uniLogin(m);
      await uniGo(m, '/pages/user/messages');
      const first = m.locator('.msg').first();
      await first.getByText(title).waitFor();
      if (await first.evaluate((e) => e.classList.contains('unread'))) throw new Error('在 PC 端看过的消息，手机端应该是已读');
      await first.locator('.view').click();
      await m.waitForURL(/pages\/coupon\/index/);
    });
    await flow.step('后台：再建一条，撤回后状态是已撤回、不能再发，测试会员也收不到', admin, async () => {
      await admin.getByRole('button', { name: '新建群发消息' }).click();
      const d = el.dialog(admin);
      await fillForm(d, revoked, config.account);
      await d.getByRole('button', { name: '提交发送' }).click();
      await el.message(admin, '已提交');
      const row = el.row(admin, revoked);
      await row.getByRole('button', { name: '撤回', exact: true }).click();
      await el.boxOk(admin);
      await el.message(admin, '已撤回');
      await row.getByText('已撤回').waitFor();
      if (await row.getByRole('button', { name: '立即发送', exact: true }).count()) throw new Error('撤回的消息不应该还能发送');
      await pc.locator('.msg-link').click();
      await pc.locator('.msg').first().waitFor();
      if (await pc.getByText(revoked).count()) throw new Error('撤回的消息不应该出现在会员的消息中心');
    });
  } finally {
    await adminCtx.close();
    await pc.context().close();
    await mctx.close();
  }

  return flow;
};
