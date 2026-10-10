const http = require('http');
const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, Skip, el, desktop, pcLogin } = require('../lib/harness');

/**
 * 电子面单（后台、PC 端）。**快递服务商一侧是模拟的**：流程自己在本机起一个假的“快递100”，后台配置里的接口地址指向它，
 * 所以这里验证的是商城自己的逻辑（配置、取号发货、失败不发货、面单入口），不是和真实快递100 的对接。
 *
 * 后台配置电子面单和顺丰账号 → 发货弹窗里出现“电子面单取号” → 服务商拒绝时提示原因、订单不发货
 * → 再点一次取号成功，订单变成已发货，可以打印面单 → 买家在 PC 端看到快递公司和单号。
 * 开发库里已经有真实的电子面单配置时整套跳过，不去动它。跑完删掉测试建的配置和账号；订单和发货记录留着。
 */

/** 假的快递100：第一次下单返回“账号密码错误”，之后返回成功；/label.png 是一张面单图片 */
function fakeCarrier() {
  const state = { calls: [], no: 'SF' + String(Date.now()).slice(-12) };
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64');
  const server = http.createServer((req, res) => {
    if (req.url.startsWith('/label.png')) {
      res.writeHead(200, { 'Content-Type': 'image/png' });
      return res.end(png);
    }
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      const form = new URLSearchParams(body);
      let param = {};
      try { param = JSON.parse(form.get('param') || '{}'); } catch (e) { /* 留空 */ }
      state.calls.push({ url: req.url, key: form.get('key'), sign: form.get('sign'), param });
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      if (state.calls.length === 1) return res.end(JSON.stringify({ success: false, code: 30001, message: '月结账号或密码错误' }));
      const port = server.address().port;
      res.end(JSON.stringify({ success: true, code: 200, message: 'success', data: { taskId: 'E2E-TASK', kuaidinum: state.no, label: `http://127.0.0.1:${port}/label.png` } }));
    });
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({ server, state, url: `http://127.0.0.1:${server.address().port}` })));
}

module.exports = async function waybillFlow(browser) {
  const flow = new Flow('waybill');
  const made = backend('waybill', { E2E_WB_STEP: 'prepare' });
  const carrier = made.skip ? null : await fakeCarrier();
  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  if (!made.skip) await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), made.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');
  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const skipIfReal = () => { if (made.skip) throw new Skip('开发库里已经有真实的电子面单配置，不去动它'); };
  const field = (d, label) => d.locator('.el-form-item', { hasText: label }).first().locator('input');

  // 发货管理里找到这张订单并打开发货弹窗，切到“电子面单取号”
  async function openWaybillShip() {
    await admin.goto(config.admin + '/order/ship');
    await admin.getByPlaceholder('订单号').fill(made.order_no);
    await admin.getByPlaceholder('订单号').press('Enter');
    await el.row(admin, made.order_no).getByRole('button', { name: '发货', exact: true }).click();
    const d = el.dialog(admin);
    await d.getByText('电子面单取号').click();
    await d.locator('.el-select', { hasText: '顺丰速运' }).waitFor();
    return d;
  }

  try {
    await flow.step('后台：电子面单配置页——填授权信息和发货人后启用；新增顺丰的快递账号，列表里密码只显示“已填”', admin, async () => {
      skipIfReal();
      await admin.goto(config.admin + '/setting/api/waybill');
      await el.row(admin, '快递100').getByText('未配置').waitFor();
      await el.row(admin, '快递100').getByRole('button', { name: '配置' }).click();
      let d = el.dialog(admin);
      await field(d, '授权 key').fill('E2E-KEY');
      await field(d, 'secret').fill('E2E-SECRET');
      await field(d, '发货人姓名').fill('宠物智能生活');
      await field(d, '发货人电话').fill('0755-12345678');
      await field(d, '发货地址').fill('广东省深圳市宝安区仓库路 8 号');
      await field(d, '接口地址').fill(carrier.url);
      await d.locator('.el-form-item', { hasText: '启用' }).last().locator('.el-switch').click();
      await d.getByRole('button', { name: '保存' }).click();
      await el.message(admin, '保存成功');
      await el.row(admin, '快递100').getByText('已配置').waitFor();

      await admin.getByRole('button', { name: '新增快递账号' }).click();
      d = el.dialog(admin);
      await d.locator('.el-select', { hasText: '顺丰速运' }).waitFor();
      await field(d, '月结账号').fill('7551234567');
      await field(d, '账号密码').fill('E2E-PASSWORD');
      await field(d, '面单模板编号').fill('E2E-TPL');
      await field(d, '备注').fill('点击测试');
      await d.getByRole('button', { name: '保存' }).click();
      await el.message(admin, '保存成功');
      const row = el.row(admin, '顺丰速运');
      await row.getByText('7551234567').waitFor();
      await row.getByText('已填').waitFor();
      if (await admin.getByText('E2E-PASSWORD').count()) throw new Error('快递账号的密码不应该显示在页面上');
    });
    await flow.step('后台：发货弹窗出现“电子面单取号”，只能选配了账号的顺丰；服务商拒绝时提示原因，订单没有发货', admin, async () => {
      skipIfReal();
      const d = await openWaybillShip();
      await d.getByRole('button', { name: '取号并发货' }).click();
      await el.message(admin, '取号失败：月结账号或密码错误');
      await admin.reload();
      await admin.getByPlaceholder('订单号').fill(made.order_no);
      await admin.getByPlaceholder('订单号').press('Enter');
      await el.row(admin, made.order_no).getByRole('button', { name: '发货', exact: true }).waitFor();
    });
    await flow.step('后台：再取一次成功——提示快递单号并问要不要打印面单；发给服务商的账号、收件人、发货人都对', admin, async () => {
      skipIfReal();
      const d = await openWaybillShip();
      await d.getByRole('button', { name: '取号并发货' }).click();
      const box = admin.locator('.el-message-box', { hasText: carrier.state.no });
      await box.getByText('现在打印面单吗').waitFor();
      await box.getByRole('button', { name: '稍后再打' }).click();
      const sent = carrier.state.calls.at(-1);
      const p = sent.param;
      if (sent.url !== '/label/order' || sent.key !== 'E2E-KEY' || !/^[0-9A-F]{32}$/.test(sent.sign || '')) throw new Error('发给服务商的请求不对：' + JSON.stringify(sent).slice(0, 200));
      if (p.kuaidicom !== 'shunfeng' || p.partnerId !== '7551234567' || p.partnerKey !== 'E2E-PASSWORD' || p.tempId !== 'E2E-TPL') throw new Error('快递账号没有带对');
      if (p.recMan?.name !== made.receiver || p.sendMan?.name !== '宠物智能生活' || p.orderId !== made.order_no) throw new Error('收件人、发货人或订单号没有带对');
    });
    await flow.step('后台：订单进了“已发货”，详情的物流里有快递单号和“打印面单”，面单图片能打开', admin, async () => {
      skipIfReal();
      await admin.getByRole('tab', { name: '已发货（待收货）' }).click();
      await admin.getByPlaceholder('订单号').fill(made.order_no);
      await admin.getByPlaceholder('订单号').press('Enter');
      await el.row(admin, made.order_no).getByRole('button', { name: '详情' }).click();
      const drawer = el.drawer(admin);
      await drawer.getByText(carrier.state.no).first().waitFor();
      const href = await drawer.getByRole('link', { name: '打印面单' }).getAttribute('href');
      const res = await admin.request.get(href);
      if (res.status() !== 200 || !(res.headers()['content-type'] || '').startsWith('image/')) throw new Error('面单图片打不开：' + href);
    });
    await flow.step('PC：买家的订单详情显示顺丰和这个快递单号，订单是待收货', pc, async () => {
      skipIfReal();
      await pcLogin(pc);
      await pc.goto(`${config.pc}/#/orders/${made.order_no}`);
      await pc.locator('.st', { hasText: '待收货' }).waitFor();
      await pc.locator('.parcel', { hasText: '顺丰速运' }).getByText(carrier.state.no).waitFor();
    });
  } finally {
    await adminCtx.close();
    await pc.context().close();
    if (carrier) carrier.server.close();
    if (!made.skip) backend('waybill', { E2E_WB_STEP: 'restore', E2E_WB_ORDER: String(made.order_id) });
  }

  return flow;
};
