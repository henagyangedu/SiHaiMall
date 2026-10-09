const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, el, desktop, mobile, pcLogin, uniGo, uniLogin } = require('../lib/harness');

/**
 * 退货退款完整流程，跨 PC 端、后台、手机端：
 * 买家对已发货订单申请退货退款 → 后台审核同意 → 买家填退货快递 → 后台确认收货并登记退款 → 各端状态一致、库存回补；
 * 另一张订单走“申请仅退款 → 后台拒绝”，买家看到拒绝原因，订单恢复原状。
 * 每跑一遍造两张已发货订单（共占用 4 件库存，退货完成的那 1 件会回补）。
 */
module.exports = async function returnsFlow(browser, data) {
  const flow = new Flow('returns');
  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), data.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');

  const sku = String(data.single.sku_id);
  const made = backend('returns', { E2E_STEP: 'make', E2E_SKU: sku });
  const [orderA, orderB] = made.orders;
  const state = (orderNo) => backend('returns', { E2E_STEP: 'state', E2E_SKU: sku, E2E_ORDER: orderNo });

  // PC 端：重新打开一个页面并刷新（同一地址连续打开时页面不会重建）
  const pcGo = async (p) => { await pc.goto(config.pc + '/#' + p); await pc.reload(); };
  // 后台：在售后订单页按单号找到一张售后单并打开详情抽屉
  const openRefund = async (refundNo) => {
    await admin.goto(config.admin + '/order/refund');
    await admin.waitForLoadState('networkidle').catch(() => {});
    await admin.getByPlaceholder(/单号/).first().fill(refundNo);
    await admin.getByPlaceholder(/单号/).first().press('Enter');
    await el.row(admin, refundNo).getByRole('button', { name: '处理' }).click();
    await el.drawer(admin).locator('.no', { hasText: refundNo }).waitFor();
  };
  // 买家在 PC 端对一张订单的商品申请售后，返回售后单号
  const pcApply = async (orderNo, type, reason) => {
    await pcGo(`/orders/${orderNo}`);
    await pc.getByRole('button', { name: '申请售后' }).click();
    await pc.waitForURL(/\/refund$/);
    // 已发货的订单不能整单退，只有一件商品时页面会自动选中它
    await pc.locator('.opt.goods.on').waitFor();
    if (await pc.locator('.opt', { hasText: '整单退款' }).count()) throw new Error('已发货的订单不应该出现整单退款');
    await pc.locator('.el-radio', { hasText: type }).click();
    await pc.locator('textarea').fill(reason);
    await pc.getByRole('button', { name: '提交申请' }).click();
    await pc.waitForURL(/#\/refunds\/RF\w+/);
    await pc.locator('.st', { hasText: '待审核' }).waitFor();
    return pc.url().match(/refunds\/(RF\w+)/)[1];
  };

  let refundA = '';
  let refundB = '';
  try {
    await flow.step('PC：已发货订单申请退货退款（2 件里退 1 件）', pc, async () => {
      await pcLogin(pc);
      refundA = await pcApply(orderA, '退货退款', '点击测试：尺寸不合适');
      await pc.locator('.status .sub', { hasText: '退货退款' }).waitFor();
      if (state(orderA).status !== 5) throw new Error('申请后订单没有变成售后中');
    });
    await flow.step('后台：审核时选拒绝但不填原因会被拦住，改为同意后等待买家退货', admin, async () => {
      await openRefund(refundA);
      await el.drawer(admin).getByRole('button', { name: '审核' }).click();
      const d = el.dialog(admin);
      await d.locator('.el-radio', { hasText: '拒绝' }).click();
      await d.getByRole('button', { name: '确定' }).click();
      await el.message(admin, '请填写拒绝原因');
      await d.locator('.el-radio', { hasText: '同意' }).click();
      await d.getByText('同意后等待买家寄回商品').waitFor();
      await d.getByRole('button', { name: '确定' }).click();
      await el.message(admin, '已同意');
      await el.drawer(admin).locator('.head .el-tag', { hasText: '待买家退货' }).waitFor();
      // 买家还没寄回，这时不能确认收货，也不能退款
      if (await el.drawer(admin).getByRole('button', { name: /确认收到退货|线下已退款|原路退款/ }).count()) throw new Error('买家还没寄回就出现了收货或退款按钮');
    });
    await flow.step('PC：看到“待寄回商品”，不填快递被拦住，填写后变为待商家收货', pc, async () => {
      await pcGo(`/refunds/${refundA}`);
      await pc.locator('.st', { hasText: '待寄回商品' }).waitFor();
      if (await pc.getByRole('button', { name: '撤销申请' }).count() === 0) throw new Error('寄回之前应该还能撤销申请');
      await pc.getByRole('button', { name: '提交快递信息' }).click();
      await el.message(pc, '请填写快递公司和单号');
      await pc.getByPlaceholder('快递公司').fill('顺丰速运');
      await pc.getByPlaceholder('快递单号').fill('SF-E2E-RETURN-001');
      await pc.getByRole('button', { name: '提交快递信息' }).click();
      await el.message(pc, '已提交，等待商家收货');
      await pc.locator('.st', { hasText: '待商家收货' }).waitFor();
      await pc.getByText('SF-E2E-RETURN-001').waitFor();
      await pc.getByPlaceholder('快递单号').waitFor({ state: 'detached' });
    });
    await flow.step('后台：详情里有退货物流，确认收到退货后进入退款中', admin, async () => {
      await openRefund(refundA);
      await el.drawer(admin).getByText('顺丰速运 SF-E2E-RETURN-001').waitFor();
      await el.drawer(admin).getByRole('button', { name: '确认收到退货' }).click();
      await el.boxOk(admin);
      await el.message(admin, '已确认收货');
      await el.drawer(admin).locator('.head .el-tag', { hasText: '退款中' }).waitFor();
    });
    await flow.step('后台：登记线下已退款，售后单完成', admin, async () => {
      await el.drawer(admin).getByRole('button', { name: '线下已退款' }).click();
      await el.boxOk(admin);
      await el.message(admin, '退款已完成');
      await el.drawer(admin).locator('.head .el-tag', { hasText: '已完成' }).waitFor();
    });
    await flow.step('退款后：订单回到待收货、部分退款，退回的 1 件库存已回补', pc, async () => {
      const s = state(orderA);
      if (s.status !== 2) throw new Error('订单状态应为待收货（2），实际 ' + s.status);
      if (s.pay_status !== 2) throw new Error('支付状态应为部分退款（2），实际 ' + s.pay_status);
      if (!(Number(s.refunded_amount) > 0 && Number(s.refunded_amount) < Number(s.pay_amount))) throw new Error(`已退金额不对：${s.refunded_amount} / ${s.pay_amount}`);
      if (s.stock !== made.stock + 1) throw new Error(`库存应回补 1 件：退款前 ${made.stock}，现在 ${s.stock}`);
      await pcGo(`/refunds/${refundA}`);
      await pc.locator('.st', { hasText: '已完成' }).waitFor();
      await pcGo(`/orders/${orderA}`);
      await pc.locator('.st', { hasText: '待收货' }).waitFor();
    });

    await flow.step('PC：另一张订单申请仅退款', pc, async () => {
      refundB = await pcApply(orderB, '仅退款', '点击测试：少发了配件');
    });
    await flow.step('后台：拒绝这次申请并填写原因', admin, async () => {
      await openRefund(refundB);
      await el.drawer(admin).getByRole('button', { name: '审核' }).click();
      const d = el.dialog(admin);
      await d.locator('.el-radio', { hasText: '拒绝' }).click();
      await d.locator('textarea').fill('点击测试：核实后配件齐全');
      await d.getByRole('button', { name: '确定' }).click();
      await el.message(admin, '已拒绝');
      await el.drawer(admin).locator('.head .el-tag', { hasText: '已拒绝' }).waitFor();
      await el.drawer(admin).getByText('点击测试：核实后配件齐全').waitFor();
    });
    await flow.step('PC：看到已拒绝和拒绝原因，订单恢复待收货、没有退款', pc, async () => {
      await pcGo(`/refunds/${refundB}`);
      await pc.locator('.st', { hasText: '已拒绝' }).waitFor();
      await pc.getByText('点击测试：核实后配件齐全').waitFor();
      const s = state(orderB);
      if (s.status !== 2 || s.pay_status !== 1 || Number(s.refunded_amount) !== 0) throw new Error('被拒绝后订单状态不对：' + JSON.stringify(s));
      await pcGo(`/orders/${orderB}`);
      await pc.locator('.st', { hasText: '待收货' }).waitFor();
      // 被拒绝后还可以再次申请
      await pc.getByRole('button', { name: '申请售后' }).waitFor();
    });

    await flow.step('手机端：两张售后单的状态、退货快递和拒绝原因显示一致', pc, async () => {
      const mctx = await browser.newContext(mobile);
      const m = flow.watch(await mctx.newPage(), '手机端');
      try {
        await uniLogin(m);
        await uniGo(m, '/pages/refund/detail?refund_no=' + refundA);
        await m.locator('.st', { hasText: '已完成' }).waitFor();
        await m.getByText('SF-E2E-RETURN-001').first().waitFor();
        await uniGo(m, '/pages/refund/detail?refund_no=' + refundB);
        await m.locator('.st', { hasText: '已拒绝' }).waitFor();
        await m.getByText('点击测试：核实后配件齐全').first().waitFor();
      } finally {
        await mctx.close();
      }
    });
  } finally {
    await pc.context().close();
    await adminCtx.close();
  }

  return flow;
};
