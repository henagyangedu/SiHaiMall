const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, Skip, el, desktop, mobile, pcLogin, uniGo, uniLogin } = require('../lib/harness');

/**
 * 税费（后台、PC 端、手机端）：
 * 后台按测试会员收货地址所在的省建一条 6% 的价外税规则 → 开关没开时结算页没有税，打开后多出税费一行、应付多出这笔税
 * → 下单，订单详情和后台订单详情都有税，然后取消订单 → 规则改成价内税，应付回到原来的数、下面标出“已含” → 删除规则后不再收税。
 * 规则是测试自己建、自己删的；“按收货地区收税”的开关在结束时恢复。那个省已经有别的税率规则时整套跳过。
 */
module.exports = async function taxFlow(browser) {
  const flow = new Flow('tax');
  const made = backend('tax');
  const skipIfTaken = () => { if (made.skip) throw new Skip('测试会员收货地址所在的地区已经有税率规则，不去动它'); };
  const label = `${made.name} 6%`;
  let orderNo = '';
  let payBefore = 0;
  let tax = 0;

  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), made.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');
  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const mctx = await browser.newContext(mobile);
  const m = flow.watch(await mctx.newPage(), '手机端');

  // 页面上的金额文字 → 数字（“¥1,234.50”“免运费”）
  const num = (text) => Number((String(text).replace(/,/g, '').match(/\d+(\.\d+)?/) || ['0'])[0]);
  const close = (a, b) => Math.abs(a - b) < 0.011;
  // 打开 PC 结算页，读出各行金额
  async function pcCheckout() {
    await pc.goto(`${config.pc}/#/checkout?sku_ids=${made.sku_id}`);
    await pc.reload();
    await pc.locator('.right .pay .total').waitFor();
    const row = async (text) => {
      const r = pc.locator('.right .kv', { hasText: text });
      return (await r.count()) ? num(await r.first().locator('span').last().innerText()) : 0;
    };
    return {
      pay: num(await pc.locator('.right .pay .total').innerText()),
      goods: await row('商品金额'), shipping: await row('运费'), discount: await row('会员折扣') || await row('优惠'),
      taxRows: await pc.locator('.right .tax-row').count(), inclRows: await pc.locator('.right .tax-incl').count(),
    };
  }

  try {
    await flow.step('后台：税率设置页提醒“还没有开始收税”；不选国家被拦住；按省份新建一条 6% 的价外税，再建同一地区的被拒绝', admin, async () => {
      skipIfTaken();
      await admin.goto(config.admin + '/setting/tax');
      await admin.getByText('现在还没有开始收税').waitFor();
      await admin.getByRole('button', { name: '新增税率规则' }).click();
      const d = el.dialog(admin);
      await d.getByRole('button', { name: '保存' }).click();
      await el.message(admin, '请选择国家 / 地区');
      const fill = async () => {
        await d.locator('.el-form-item', { hasText: '国家 / 地区' }).locator('.el-select').click();
        await el.option(admin, '中国大陆').click();
        await d.locator('.el-form-item', { hasText: '省 / 州' }).locator('.el-select').click();
        await el.option(admin, made.province).click();
        await d.getByPlaceholder('如 VAT、Sales Tax、GST，会显示给买家').fill(made.name);
        await d.locator('.el-form-item', { hasText: '税率' }).locator('input').first().fill('6');
      };
      await fill();
      await d.getByText('税在商品价之外另收').waitFor();
      await d.getByRole('button', { name: '保存' }).click();
      await el.message(admin, '保存成功');
      const row = el.row(admin, made.name);
      await row.getByText(made.province).waitFor();
      await row.getByText('价外税（另加）').waitFor();

      await admin.getByRole('button', { name: '新增税率规则' }).click();
      await fill();
      await d.getByRole('button', { name: '保存' }).click();
      await el.message(admin, '这个地区已经有一条税率规则了');
      await d.getByRole('button', { name: '取消' }).click();
    });
    await flow.step('PC：开关没开时结算页没有税；打开后多出“税费”一行，是商品实付的 6%，应付正好多出这笔税', pc, async () => {
      skipIfTaken();
      await pcLogin(pc);
      const off = await pcCheckout();
      if (off.taxRows || off.inclRows) throw new Error('开关没开，结算页不应该有税');
      payBefore = off.pay;
      backend('tax', { E2E_STEP: 'switch', E2E_VALUE: '1' });
      const on = await pcCheckout();
      await pc.locator('.right .tax-row', { hasText: `税费（${label}）` }).waitFor();
      tax = num(await pc.locator('.right .tax-row span').last().innerText());
      const net = off.pay - off.shipping;
      if (!close(tax, Math.round(net * 6) / 100)) throw new Error(`税额不对：商品实付 ${net}，税 ${tax}`);
      if (!close(on.pay, off.pay + tax)) throw new Error(`应付不对：原来 ${off.pay}，税 ${tax}，现在 ${on.pay}`);
    });
    await flow.step('PC：提交订单，订单详情里有税费这一行；后台订单详情标出税名、税率和“价外税”；然后取消订单', pc, async () => {
      skipIfTaken();
      await pc.getByRole('button', { name: '提交订单' }).click();
      await pc.waitForURL(/#\/orders\/GM\w+/);
      orderNo = pc.url().match(/orders\/(GM\w+)/)[1];
      const dlg = pc.locator('.el-dialog:visible', { hasText: '选择支付方式' });
      await dlg.waitFor();
      await dlg.getByRole('button', { name: '取消' }).click();
      const row = pc.locator('.sum .tax-row', { hasText: `税费（${label}）` });
      await row.waitFor();
      if (!close(num(await row.locator('span').last().innerText()), tax)) throw new Error('订单详情里的税额和结算页不一样');

      await admin.goto(config.admin + '/order/list');
      await admin.getByPlaceholder('订单号').fill(orderNo);
      await admin.getByPlaceholder('订单号').press('Enter');
      await el.row(admin, orderNo).getByRole('button', { name: '详情' }).click();
      await el.drawer(admin).getByText(`税费：+${tax.toFixed(2)}`).waitFor();
      await el.drawer(admin).getByText(`${label}，价外税`).waitFor();

      await pc.getByRole('button', { name: '取消订单' }).click();
      await el.boxOk(pc);
      await pc.locator('.st', { hasText: '已取消' }).waitFor();
    });
    await flow.step('后台把规则改成价内税：PC 和手机端结算页的应付回到原来的数，下面标出“已含”多少税', admin, async () => {
      skipIfTaken();
      await admin.goto(config.admin + '/setting/tax');
      await el.row(admin, made.name).getByRole('button', { name: '编辑' }).click();
      const d = el.dialog(admin);
      await d.getByText('价内税（标价已含）').click();
      await d.getByText('商品标价已经含税').waitFor();
      await d.getByRole('button', { name: '保存' }).click();
      await el.message(admin, '保存成功');
      await el.row(admin, made.name).getByText('价内税（标价已含）').waitFor();

      const incl = await pcCheckout();
      if (incl.taxRows) throw new Error('价内税不应该有另加的税费行');
      if (!close(incl.pay, payBefore)) throw new Error(`价内税应付应该不变：原来 ${payBefore}，现在 ${incl.pay}`);
      await pc.locator('.right .tax-incl', { hasText: `已含${made.name}（6%）` }).waitFor();

      await uniLogin(m);
      await uniGo(m, `/pages/checkout/index?sku_ids=${made.sku_id}`);
      await m.locator('.tax-incl', { hasText: `已含${made.name}（6%）` }).waitFor();
      if (await m.locator('.tax-row').count()) throw new Error('手机端：价内税不应该有另加的税费行');
    });
    await flow.step('后台：停用规则后结算页不再有税；删除规则', admin, async () => {
      skipIfTaken();
      await el.row(admin, made.name).locator('.el-switch').click();
      await el.message(admin, '已停用');
      const none = await pcCheckout();
      if (none.taxRows || none.inclRows) throw new Error('规则停用后结算页还有税');
      await el.row(admin, made.name).getByRole('button', { name: '删除' }).click();
      await el.boxOk(admin);
      await el.message(admin, '已删除');
      await admin.locator('.el-table__row', { hasText: made.name }).waitFor({ state: 'detached' });
    });
  } finally {
    if (!made.skip) backend('tax', { E2E_STEP: 'restore', E2E_VALUE: String(made.enabled_original) });
    await adminCtx.close();
    await pc.context().close();
    await mctx.close();
  }

  return flow;
};
