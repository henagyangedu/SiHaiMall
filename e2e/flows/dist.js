const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, el, desktop, mobile, pcLogin, uniGo, uniLogin } = require('../lib/harness');

/**
 * 分销完整流程，跨 PC 端、后台、手机端：
 * 申请分销员 → 后台审核 → 邀请链接注册下级 → 下级下单并结算佣金 → 提现 → 后台审核打款 → 各端显示一致。
 * 过程中会临时开启分销，结束时恢复成原来的配置。可以重复运行（测试会员已经是分销员时跳过申请）。
 */
module.exports = async function distFlow(browser, data) {
  const flow = new Flow('dist');
  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), data.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');
  const goAdmin = async (p) => { await admin.goto(config.admin + p); await admin.waitForLoadState('networkidle').catch(() => {}); };
  // 重新进入分销中心并刷新，拿到最新数据
  const pcDist = async () => { await pc.goto(config.pc + '/#/distribution'); await pc.reload(); };
  // 分销员在后台列表里显示为“昵称 + ID”
  const adminRow = () => admin.locator('.el-table__row').filter({ hasText: data.nickname }).filter({ hasText: `ID ${data.member_id}` }).first();

  const state = backend('dist', { E2E_STEP: 'on' });
  let inviteCode = '';
  let inviteLink = '';
  let order = null;
  const email = `e2e${Date.now().toString().slice(-8)}@example.com`;
  const nick = '点击下级';
  // 下级的名字在佣金明细和团队里是打码显示的：只留首尾两个字，中间每个字换成一个 *
  const masked = nick[0] + '*'.repeat(nick.length - 2) + nick[nick.length - 1];

  try {
    await flow.step('PC：会员登录后在分销中心提交申请', pc, async () => {
      await pcLogin(pc);
      await pcDist();
      await pc.locator('.panel-title', { hasText: '分销中心' }).waitFor();
      if (state.distributor_status === 1) return; // 已经是分销员
      if (state.distributor_status === 0) return pc.getByText('申请正在审核中').waitFor();
      await pc.getByRole('button', { name: /提交申请|重新申请/ }).click();
      await el.message(pc, '请填写真实姓名'); // 不填姓名被拦住
      await pc.getByPlaceholder('真实姓名').fill('点击测试');
      await pc.getByRole('button', { name: /提交申请|重新申请/ }).click();
      await pc.getByText('申请正在审核中').waitFor();
    });
    await flow.step('后台：分销员列表里通过这份申请', admin, async () => {
      await goAdmin('/distribution/distributor');
      await adminRow().waitFor();
      if (await adminRow().getByRole('button', { name: '通过' }).count()) {
        await adminRow().getByRole('button', { name: '通过' }).click();
        await el.boxOk(admin);
      }
      await adminRow().getByText('正常').waitFor();
    });
    await flow.step('PC：通过后看到概览、邀请码和邀请链接', pc, async () => {
      await pcDist();
      await pc.getByRole('tab', { name: '概览' }).waitFor();
      inviteCode = (await pc.locator('.code').textContent()).trim();
      inviteLink = (await pc.locator('.url').textContent()).replace('复制', '').trim();
      if (!inviteCode || !inviteLink.includes('invite=' + inviteCode)) throw new Error(`邀请链接不对：${inviteLink} / ${inviteCode}`);
    });

    await flow.step('PC：新访客打开邀请链接，注册页自动带上邀请码并注册成功', pc, async () => {
      const guest = flow.watch(await (await browser.newContext(desktop)).newPage(), '下级');
      try {
        await guest.goto(inviteLink);
        await guest.getByPlaceholder('邀请码（可选）').waitFor();
        if ((await guest.getByPlaceholder('邀请码（可选）').inputValue()) !== inviteCode) throw new Error('邀请码没有自动填上');
        await guest.getByPlaceholder('邮箱或手机号').fill(email);
        await guest.getByPlaceholder('密码').fill('Secret#123');
        await guest.getByPlaceholder('昵称（可选）').fill(nick);
        await guest.locator('.submit').click();
        await guest.locator('.user', { hasText: nick }).waitFor();
      } finally {
        await guest.context().close();
      }
    });
    await flow.step('下级下单、收货并过了售后期后，佣金结算给上级', pc, async () => {
      // 这一步由后端脚本推进（造订单、发货、收货、挪完成时间、跑结算），不在页面上点
      order = backend('dist', { E2E_STEP: 'order', E2E_BUYER: email, E2E_SKU: String(data.single.sku_id) });
      if (order.parent_id !== data.member_id) throw new Error('下级没有绑定到邀请人：parent_id=' + order.parent_id);
      if (!order.commission.length || Number(order.commission[0].status) !== 1) throw new Error('佣金没有生成或没有结算：' + JSON.stringify(order.commission));
    });

    await flow.step('PC：佣金明细里有这笔订单，已结算，买家名字打码显示', pc, async () => {
      await pcDist();
      await pc.getByRole('tab', { name: '佣金明细' }).click();
      const r = pc.locator('.el-tab-pane:visible .el-table__row', { hasText: order.order_no }).first();
      await r.getByText('已结算').waitFor();
      await r.getByText(masked).waitFor();
      if ((await r.textContent()).includes(nick)) throw new Error('买家全名没有打码');
    });
    await flow.step('PC：我的团队里有刚注册的下级', pc, async () => {
      await pc.getByRole('tab', { name: '我的团队' }).click();
      await pc.locator('.el-tab-pane:visible .el-table__row', { hasText: masked }).first().waitFor();
    });
    await flow.step('PC：提现缺开户行、低于最低金额都被拦住，合规的申请提交成功', pc, async () => {
      await pc.getByRole('tab', { name: '提现' }).click();
      const form = pc.locator('.wd');
      const field = (label) => form.locator('.el-form-item', { hasText: label }).locator('input');
      await field('提现金额').fill('5');
      await field('收款人姓名').fill('点击测试');
      await field('收款账号').fill('6222000000000000');
      await form.getByRole('button', { name: '提交提现申请' }).click();
      await el.message(pc, '请填写开户行');
      await field('开户行').fill('测试银行');
      await form.getByRole('button', { name: '提交提现申请' }).click();
      await el.message(pc, '提现金额不能低于');
      await field('提现金额').fill('10');
      await form.getByRole('button', { name: '提交提现申请' }).click();
      await el.message(pc, '提现申请已提交');
      await pc.locator('.el-tab-pane:visible .el-table__row', { hasText: '待审核' }).first().waitFor();
    });

    await flow.step('后台：佣金明细里能看到这笔佣金', admin, async () => {
      await goAdmin('/distribution/commission');
      await el.row(admin, order.order_no).getByText('已结算').waitFor();
    });
    await flow.step('后台：提现管理里审核通过并登记打款', admin, async () => {
      await goAdmin('/distribution/withdraw');
      // 重复运行后这个会员会有多条提现记录：始终处理“待审核 / 待打款”的那一条
      const pending = () => admin.locator('.el-table__row').filter({ hasText: data.nickname }).filter({ hasText: /待审核|待打款/ }).first();
      await pending().getByRole('button', { name: '通过' }).click();
      await el.boxOk(admin);
      await pending().getByRole('button', { name: '登记打款' }).click();
      await admin.locator('.el-message-box__input input').fill('点击测试流水 001');
      await el.boxOk(admin);
      await pending().waitFor({ state: 'detached' });
    });
    await flow.step('PC：提现记录变为已打款，已提现金额增加', pc, async () => {
      await pcDist();
      const withdrawn = Number((await pc.locator('.stat', { hasText: '已提现' }).locator('.n').textContent()).replace(/[^\d.]/g, ''));
      if (!(withdrawn >= 10)) throw new Error('已提现金额不对：' + withdrawn);
      await pc.getByRole('tab', { name: '提现' }).click();
      const first = pc.locator('.el-tab-pane:visible .el-table__row').first();
      await first.getByText('已打款').waitFor();
    });

    await flow.step('手机端：分销中心、佣金明细、团队、提现记录显示一致', pc, async () => {
      const mctx = await browser.newContext(mobile);
      const m = flow.watch(await mctx.newPage(), '手机端');
      try {
        await uniLogin(m);
        await uniGo(m, '/pages/distribution/index');
        await m.locator('.code', { hasText: inviteCode }).waitFor();
        await uniGo(m, '/pages/distribution/records?type=commission');
        await m.getByText(order.order_no).first().waitFor();
        await uniGo(m, '/pages/distribution/records?type=team');
        await m.getByText(masked).first().waitFor();
        await uniGo(m, '/pages/distribution/withdraw');
        await m.locator('.status', { hasText: '已打款' }).first().waitFor();
      } finally {
        await mctx.close();
      }
    });
  } finally {
    // 无论成功失败，都把分销配置恢复成运行前的样子
    backend('dist', { E2E_STEP: 'off', E2E_DIST_CONFIG: JSON.stringify(state.original) });
    await pc.context().close();
    await adminCtx.close();
  }

  return flow;
};
