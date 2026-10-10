const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, el, desktop, mobile, pcLogin, uniGo, uniLogin } = require('../lib/harness');

/**
 * 订单发票（PC 端、手机端、后台）：
 * 买家在 PC 端订单详情申请发票（企业不填税号被拦住）→ 手机端看到待开票 → 后台驳回并填原因
 * → 手机端看到原因，改了重新提交 → 后台上传 PDF、登记发票号码 → PC 端显示已开票，能打开发票文件，不能再改
 * → 另一张订单申请时自动带出上次的抬头。
 * 运行期间打开“允许申请发票”，结束时恢复；两张订单和开出来的发票留在开发库里当例子。
 */

/** 一份最小的、能被阅读器打开的 PDF（一页，一行字）：上传发票文件用 */
function samplePdf(text) {
  const objs = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 420 200] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    null,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ];
  const stream = `BT /F1 16 Tf 40 120 Td (${text}) Tj ET`;
  objs[3] = `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`;
  let out = '%PDF-1.4\n';
  const offsets = [];
  objs.forEach((o, i) => { offsets.push(out.length); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const xref = out.length;
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offsets.map((n) => String(n).padStart(10, '0') + ' 00000 n \n').join('');
  out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return Buffer.from(out, 'latin1');
}

module.exports = async function invoiceFlow(browser) {
  const flow = new Flow('invoice');
  const made = backend('invoice', { E2E_INV_STEP: 'prepare' });
  const company = '杭州点击测试科技有限公司';
  const invoiceNo = '2533' + String(Date.now()).slice(-12) + '0001';
  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), made.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');
  const mctx = await browser.newContext(mobile);
  const m = flow.watch(await mctx.newPage(), '手机端');
  const panel = pc.locator('.invoice');

  // 后台列表默认只看待开票，按状态切换后找到这张订单的那一行
  async function adminRow(status) {
    await admin.locator('.el-form .el-select').first().click();
    await el.option(admin, status).click();
    const row = el.row(admin, made.order_no);
    await row.waitFor();
    return row;
  }

  try {
    await flow.step('PC：已付款订单的详情里有“发票”一栏；选企业不填税号被拦住，填好后变成待开票', pc, async () => {
      await pcLogin(pc);
      await pc.goto(`${config.pc}/#/orders/${made.order_no}`);
      await panel.getByText('这张订单还没有申请发票').waitFor();
      await panel.getByRole('button', { name: '申请发票' }).click();
      const d = el.dialog(pc);
      await d.getByText('3 个工作日内开出').waitFor();
      await d.getByText('企业', { exact: true }).click();
      await d.getByPlaceholder('企业全称').fill(company);
      // 以前跑过这套流程的话，弹窗会带出上次的税号，先清掉
      await d.locator('.el-form-item', { hasText: '纳税人识别号' }).locator('input').fill('');
      await d.locator('.el-form-item', { hasText: '收票邮箱' }).locator('input').fill('finance-e2e@example.com');
      await d.getByRole('button', { name: '提交申请' }).click();
      await el.message(pc, '请填写纳税人识别号');
      await d.locator('.el-form-item', { hasText: '纳税人识别号' }).locator('input').fill('123');
      await d.getByRole('button', { name: '提交申请' }).click();
      await el.message(pc, '请填写正确的纳税人识别号');
      await d.locator('.el-form-item', { hasText: '纳税人识别号' }).locator('input').fill('91330100MA2E2ETEST');
      await d.getByPlaceholder('开户银行').fill('招商银行杭州分行');
      await d.getByRole('button', { name: '提交申请' }).click();
      await el.message(pc, '发票申请已提交');
      await panel.getByText('待开票').waitFor();
      await panel.getByText(company).waitFor();
      await panel.getByRole('button', { name: '撤销申请' }).waitFor();
    });
    await flow.step('手机端：订单详情的“发票”显示待开票，点进去能看到抬头和税号', m, async () => {
      await uniLogin(m);
      await uniGo(m, `/pages/order/detail?order_no=${made.order_no}`);
      const cell = m.locator('.card.invoice');
      await cell.getByText('待开票').waitFor();
      await cell.click();
      await m.waitForURL(/pages\/order\/invoice/);
      await m.getByText(company).waitFor();
      await m.getByText('91330100MA2E2ETEST').waitFor();
    });
    await flow.step('后台：发票管理里有这条待开票的申请，驳回（不填原因被拦住）', admin, async () => {
      await admin.goto(config.admin + '/order/invoice');
      const row = el.row(admin, made.order_no);
      await row.getByText(company).waitFor();
      await row.getByText('开户银行：招商银行杭州分行').waitFor();
      await row.getByRole('button', { name: '驳回' }).click();
      await el.boxOk(admin);
      await admin.locator('.el-message-box__errormsg', { hasText: '请填写原因' }).waitFor();
      await admin.locator('.el-message-box textarea').fill('税号和企业名称对不上，请核对');
      await el.boxOk(admin);
      await el.message(admin, '已驳回');
      await (await adminRow('已驳回')).getByText('税号和企业名称对不上').waitFor();
    });
    await flow.step('手机端：看到已驳回和原因，点“重新申请”改税号后提交，回到待开票', m, async () => {
      await uniGo(m, `/pages/order/invoice?order_no=${made.order_no}`);
      await m.getByText('已驳回').waitFor();
      await m.getByText('税号和企业名称对不上').waitFor();
      await m.locator('.btn', { hasText: '重新申请' }).click();
      const tax = m.locator('uni-input input').nth(1);
      await m.waitForFunction(() => document.querySelectorAll('uni-input input')[1]?.value === '91330100MA2E2ETEST');
      await tax.fill('91330100MA2E2ETES8');
      await m.locator('.btn.submit').click();
      await m.getByText('待开票').waitFor();
      await m.getByText('91330100MA2E2ETES8').waitFor();
    });
    await flow.step('后台：开票——不传文件被拦住；上传 PDF、填发票号码后变成已开票', admin, async () => {
      const row = await adminRow('待开票');
      await row.getByRole('button', { name: '开票' }).click();
      const d = el.dialog(admin);
      await d.getByText('91330100MA2E2ETES8').waitFor();
      await d.getByPlaceholder('开票软件给出的发票号码').fill(invoiceNo);
      await d.getByRole('button', { name: '确认开票' }).click();
      await el.message(admin, '请上传发票文件');
      await d.locator('input[type=file]').setInputFiles({ name: '电子发票.pdf', mimeType: 'application/pdf', buffer: samplePdf(`Sample e-invoice ${invoiceNo}`) });
      await d.locator('.file.picked').waitFor();
      await d.getByRole('button', { name: '确认开票' }).click();
      await el.message(admin, '已登记开票');
      const done = await adminRow('已开票');
      await done.getByText(invoiceNo).waitFor();
      await done.getByText('查看文件').waitFor();
    });
    await flow.step('PC：订单详情显示已开票和发票号码，“查看发票”打开的是那份 PDF；不能再修改、撤销；消息里有通知', pc, async () => {
      await pc.reload();
      await panel.getByText('已开票').waitFor();
      await panel.getByText(invoiceNo).waitFor();
      const href = await panel.locator('a.file').getAttribute('href');
      const res = await pc.request.get(new URL(href, config.pc).href);
      if (res.status() !== 200 || !(await res.body()).subarray(0, 5).equals(Buffer.from('%PDF-'))) throw new Error('发票文件打不开：' + href);
      if (await panel.getByRole('button', { name: '撤销申请' }).count()) throw new Error('开出后不应该还能撤销');
      if (await panel.getByRole('button', { name: /修改|重新申请/ }).count()) throw new Error('开出后不应该还能修改');
      await pc.locator('.msg-link').click();
      await pc.locator('.msg', { hasText: '发票已开具' }).first().getByText(invoiceNo).waitFor();
    });
    await flow.step('PC：另一张订单申请发票时，自动带出上次的抬头、税号和邮箱', pc, async () => {
      await pc.goto(`${config.pc}/#/orders/${made.second_no}`);
      await panel.getByRole('button', { name: '申请发票' }).click();
      const d = el.dialog(pc);
      const title = d.getByPlaceholder('企业全称');
      await title.waitFor();
      if ((await title.inputValue()) !== company) throw new Error('应该带出上次的抬头，实际是：' + (await title.inputValue()));
      if ((await d.locator('.el-form-item', { hasText: '纳税人识别号' }).locator('input').inputValue()) !== '91330100MA2E2ETES8') throw new Error('应该带出上次的税号');
      await d.getByRole('button', { name: '取消' }).click();
    });
  } finally {
    await pc.context().close();
    await adminCtx.close();
    await mctx.close();
    backend('invoice', { E2E_INV_STEP: 'restore', E2E_INV_ENABLED: String(made.enabled_was) });
  }

  return flow;
};
