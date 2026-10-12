const http = require('http');
const crypto = require('crypto');
const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, el, desktop, pcLogin } = require('../lib/harness');

/**
 * 开放接口（后台页面 + 直接调后端接口 + PC 端）：
 * 后台“设置 → 开放接口”新建一个只有订单和发货权限的应用，密钥只显示一次 → 用这把密钥调接口：连通测试、没权限的被拒、拉到刚付款的订单
 * → 用接口给这张订单发货，重复提交不会发两次 → 买家在 PC 端看到已发货和快递单号 → 后台的调用记录里有这次发货
 * → 对方系统收到“订单发货”的通知，签名对得上 → 后台的推送记录、发送测试通知、对方出故障时重发失败、恢复后重发成功
 * → 重新生成密钥后旧的失效；停用后新的也不能用 → 删除应用。
 * 每遍造一张已付款订单并把它发货，占 1 件库存；应用是测试自己建、自己删的。
 * 接口是直接请求后端的（地址取自后台开发服务器的转发目标，见 lib/config.js 的 api）。
 * “对方系统”是流程自己在本机起的一个小服务，所以要求后端能访问到本机的 127.0.0.1。
 */

/** 假的对方系统：把收到的通知都记下来；down 为 true 时装作出了故障 */
function fakeReceiver() {
  const state = { got: [], down: false };
  const server = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      state.got.push({ headers: req.headers, body });
      res.writeHead(state.down ? 500 : 200, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end(state.down ? '对方系统出故障了' : 'ok');
    });
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({ server, state, url: `http://127.0.0.1:${server.address().port}/hook` })));
}
module.exports = async function openapiFlow(browser) {
  const flow = new Flow('openapi');
  const made = backend('openapi');
  const appName = '点击测试 ERP';
  const expressNo = 'E2E' + Date.now();
  let key = '';
  let secret = '';
  const receiver = await fakeReceiver();
  /** 等对方系统收到某个事件的通知（推送是在接口返回之后才发的） */
  async function waitEvent(event, from = 0) {
    for (let i = 0; i < 40; i++) {
      const hit = receiver.state.got.slice(from).find((g) => g.headers['x-shm-event'] === event);
      if (hit) return hit;
      await new Promise((r) => setTimeout(r, 250));
    }
    throw new Error(`对方系统 10 秒内没有收到 ${event} 的通知（后端要能访问到 ${receiver.url}）`);
  }

  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), made.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');
  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  // 直接请求后端。本机的后端用的是自签名证书
  const client = await browser.newContext({ ignoreHTTPSErrors: true });

  /** 调开放接口，返回 {code, msg, data} */
  async function call(method, path, k, data) {
    const res = await client.request.fetch(`${config.api}/open-api/v1${path}`, {
      method, headers: { Accept: 'application/json', ...(k ? { Authorization: `Bearer ${k}` } : {}) },
      ...(method === 'GET' ? { params: data } : { data }),
    });
    const text = await res.text();
    try { return JSON.parse(text); } catch (e) { throw new Error(`${method} ${path} 返回的不是 JSON（HTTP ${res.status()}）：${text.slice(0, 200)}`); }
  }
  const expect = (res, code, what) => { if (res.code !== code) throw new Error(`${what}：应该返回 ${code}，实际是 ${res.code} ${res.msg}`); };
  /** 读出“请保存密钥”弹窗里的密钥并关掉弹窗。弹窗里同时有推送的签名密钥时记到 secret */
  async function takeKey() {
    const d = el.dialog(admin);
    await d.getByText('密钥只显示这一次').waitFor();
    const value = await d.locator('.key-input:not(.secret-input) input').inputValue();
    if (!/^shm_[A-Za-z0-9]{40}$/.test(value)) throw new Error('密钥的样子不对：' + value);
    if (await d.locator('.secret-input').count()) {
      secret = await d.locator('.secret-input input').inputValue();
      if (!/^whsec_[A-Za-z0-9]{40}$/.test(secret)) throw new Error('签名密钥的样子不对：' + secret);
    }
    await d.getByRole('button', { name: '我已保存' }).click();
    await d.waitFor({ state: 'hidden' });
    return value;
  }

  try {
    await flow.step('后台：新建应用——不填名称、不选权限都被拦住；只勾“读取订单”和“发货”，填上推送地址、订阅“订单发货”（售后的事件没有财务权限勾不了），创建后密钥和签名密钥只显示一次', admin, async () => {
      await admin.goto(config.admin + '/setting/open-api');
      await admin.getByRole('button', { name: '新建应用' }).click();
      const d = el.dialog(admin);
      await d.getByRole('button', { name: '创建并生成密钥' }).click();
      await el.message(admin, '请填写应用名称');
      await d.getByPlaceholder('对方系统的名称').fill(appName);
      await d.getByRole('button', { name: '创建并生成密钥' }).click();
      await el.message(admin, '请至少选择一项权限');
      await d.locator('.el-checkbox', { hasText: '读取订单' }).first().click();
      await d.locator('.el-checkbox', { hasText: '发货、修改物流单号' }).first().click();
      // 主动推送：开了但没填地址、没选事件都被拦住
      await d.locator('.webhook-switch').click();
      await d.getByRole('button', { name: '创建并生成密钥' }).click();
      await el.message(admin, '开启主动推送要先填写接收地址');
      await d.locator('.webhook-url input').fill(receiver.url);
      await d.getByRole('button', { name: '创建并生成密钥' }).click();
      await el.message(admin, '开启主动推送要至少订阅一个事件');
      const events = d.locator('.webhook-events');
      if (!(await events.locator('.el-checkbox', { hasText: '退款完成' }).getAttribute('class')).includes('is-disabled')) throw new Error('没有财务权限时，“退款完成”应该勾不了');
      await events.locator('.el-checkbox', { hasText: '订单发货' }).click();
      await d.getByPlaceholder('选填，如对接人、用途').fill('点击测试建的，可以删');
      await d.getByRole('button', { name: '创建并生成密钥' }).click();
      key = await takeKey();
      if (!secret) throw new Error('填了推送地址，创建后应该同时给出签名密钥');

      const row = el.row(admin, appName);
      await row.waitFor();
      await row.getByText('已开启 · 1 个事件').waitFor();
      if ((await admin.content()).includes(secret)) throw new Error('关掉弹窗后页面上不应该还有签名密钥');
      await row.getByText(key.slice(0, 10) + '…').waitFor();
      if ((await admin.content()).includes(key)) throw new Error('关掉弹窗后页面上不应该还有完整的密钥');
      await row.getByText('还没有调用过').waitFor();
    });

    await flow.step('接口：不带密钥被拒；连通测试返回应用名称和权限；没勾的权限（规格、库存、财务）调不了；按状态拉订单能拉到刚付款的那张', admin, async () => {
      expect(await call('GET', '/ping'), 401, '不带密钥');
      expect(await call('GET', '/ping', 'shm_' + 'x'.repeat(40)), 401, '乱写的密钥');
      const ping = await call('GET', '/ping', key);
      expect(ping, 0, '连通测试');
      if (ping.data.app !== appName || ping.data.scopes.sort().join() !== 'order.read,order.ship') throw new Error('连通测试返回的内容不对：' + JSON.stringify(ping.data));
      expect(await call('GET', '/skus', key), 403, '没有规格权限');
      expect(await call('POST', '/stock', key, { items: [{ sku_id: made.sku_id, mode: 'add', qty: 1 }] }), 403, '没有库存权限');
      expect(await call('GET', '/pay-trades', key), 403, '没有财务权限');

      const since = new Date(Date.now() - 3600e3);
      const pad = (n) => String(n).padStart(2, '0');
      // 接口里的时间是北京时间
      const bj = new Date(since.getTime() + (since.getTimezoneOffset() + 480) * 60e3);
      const after = `${bj.getFullYear()}-${pad(bj.getMonth() + 1)}-${pad(bj.getDate())} ${pad(bj.getHours())}:${pad(bj.getMinutes())}:${pad(bj.getSeconds())}`;
      const list = await call('GET', '/orders', key, { status: 1, updated_after: after, page_size: 100 });
      expect(list, 0, '订单列表');
      const mine = list.data.list.find((o) => o.order_no === made.order_no);
      if (!mine) throw new Error(`订单列表里没有 ${made.order_no}（共 ${list.data.total} 张）`);
      if (mine.pay_status !== 1 || !mine.items.length || !mine.address || mine.items[0].shipped) throw new Error('订单内容不对：' + JSON.stringify(mine).slice(0, 300));
    });

    await flow.step('接口：给这张订单发货——快递公司编码不对被拒；发货成功；同一个单号再提交一次返回同一条记录；订单详情里变成待收货', admin, async () => {
      expect(await call('POST', `/orders/${made.order_no}/ship`, key, { express_code: 'NOPE', express_no: expressNo }), 422, '不认识的快递公司');
      const done = await call('POST', `/orders/${made.order_no}/ship`, key, { express_code: 'SF', express_no: expressNo });
      expect(done, 0, '发货');
      const again = await call('POST', `/orders/${made.order_no}/ship`, key, { express_code: 'SF', express_no: expressNo });
      expect(again, 0, '重复提交');
      if (again.data.id !== done.data.id) throw new Error('重复提交应该返回同一条发货记录');
      const detail = await call('GET', `/orders/${made.order_no}`, key);
      expect(detail, 0, '订单详情');
      if (detail.data.status !== 2 || detail.data.shipments.length !== 1 || detail.data.shipments[0].express_no !== expressNo || !detail.data.items[0].shipped) {
        throw new Error('发货后的订单内容不对：' + JSON.stringify(detail.data).slice(0, 300));
      }
    });

    await flow.step('推送：对方系统收到一条“订单发货”的通知，带着订单号和快递单号，签名用签名密钥验得过；重复提交的那次发货没有再通知', admin, async () => {
      const hit = await waitEvent('order.shipped');
      const ts = hit.headers['x-shm-timestamp'];
      const want = 'sha256=' + crypto.createHmac('sha256', secret).update(ts + '.' + hit.body).digest('hex');
      if (hit.headers['x-shm-signature'] !== want) throw new Error('签名对不上');
      if (Math.abs(Date.now() / 1000 - Number(ts)) > 120) throw new Error('时间戳不对：' + ts);
      const msg = JSON.parse(hit.body);
      if (msg.id !== hit.headers['x-shm-delivery'] || msg.event !== 'order.shipped' || msg.data.order_no !== made.order_no || msg.data.express_no !== expressNo || msg.data.status !== 2) {
        throw new Error('通知的内容不对：' + hit.body);
      }
      if (hit.body.includes('phone')) throw new Error('通知里不应该有收货信息');
      await new Promise((r) => setTimeout(r, 1500));
      if (receiver.state.got.length !== 1) throw new Error('应该只收到 1 条通知，实际 ' + receiver.state.got.length + ' 条（重复提交的发货不该再发）');
    });

    await flow.step('PC：买家的订单详情显示待收货和这个快递单号', pc, async () => {
      await pcLogin(pc);
      await pc.goto(`${config.pc}/#/orders/${made.order_no}`);
      await pc.getByText('待收货').first().waitFor();
      await pc.getByText(expressNo).first().waitFor();
    });

    await flow.step('后台：订单日志写明是这个应用发的货；应用的调用记录里有这次发货（成功两条、失败一条），读取数据的调用不在里面', admin, async () => {
      await admin.goto(config.admin + '/order/list');
      await admin.getByPlaceholder('订单号').first().fill(made.order_no);
      await admin.keyboard.press('Enter');
      await el.row(admin, made.order_no).getByRole('button', { name: '详情' }).click();
      await el.drawer(admin).getByText(`开放接口（${appName}）：已发货`).first().waitFor();
      await admin.keyboard.press('Escape');

      await admin.goto(config.admin + '/setting/open-api');
      const row = el.row(admin, appName);
      await row.waitFor();
      if (await row.getByText('还没有调用过').count()) throw new Error('调用过之后应该显示最近调用的时间');
      await row.getByRole('button', { name: '调用记录' }).click();
      const drawer = el.drawer(admin);
      await drawer.locator('.el-table__row', { hasText: `orders/${made.order_no}/ship` }).first().waitFor();
      const rows = drawer.locator('.el-table__row');
      if ((await rows.count()) !== 3) throw new Error('调用记录应该是 3 条，实际是 ' + (await rows.count()));
      if ((await drawer.locator('.el-tag', { hasText: '成功' }).count()) !== 2 || (await drawer.locator('.el-tag', { hasText: '失败 422' }).count()) !== 1) throw new Error('调用记录的结果不对');
      await admin.keyboard.press('Escape');
    });

    await flow.step('后台：推送记录里有这条发货通知（成功），展开能看到正文；发送测试通知，对方收到；对方出故障时重发失败并显示对方的应答，恢复后重发成功', admin, async () => {
      await el.row(admin, appName).getByRole('button', { name: '推送记录' }).click();
      const drawer = el.drawer(admin);
      const shipped = drawer.locator('.el-table__row', { hasText: made.order_no });
      await shipped.waitFor();
      await shipped.getByText('订单发货').waitFor();
      await shipped.locator('.el-tag', { hasText: '成功' }).waitFor();
      await shipped.getByText('HTTP 200').waitFor();
      await shipped.locator('.el-table__expand-icon').click();
      await drawer.locator('.hook-detail pre', { hasText: expressNo }).waitFor();

      const before = receiver.state.got.length;
      await drawer.getByRole('button', { name: '发送测试通知' }).click();
      await el.message(admin, '对方已收到');
      await waitEvent('ping', before);
      await drawer.locator('.el-table__row', { hasText: '测试通知' }).locator('.el-tag', { hasText: '成功' }).waitFor();

      receiver.state.down = true;
      await shipped.getByRole('button', { name: '重发' }).click();
      await el.message(admin, '没有发成功');
      await shipped.locator('.el-tag', { hasText: '失败' }).waitFor();
      await shipped.getByText('对方系统出故障了').waitFor();

      receiver.state.down = false;
      await shipped.getByRole('button', { name: '重发' }).click();
      await shipped.locator('.el-tag', { hasText: '成功' }).waitFor();
      const resent = receiver.state.got.filter((g) => g.headers['x-shm-event'] === 'order.shipped');
      if (resent.length !== 3 || new Set(resent.map((g) => g.headers['x-shm-delivery'])).size !== 1) throw new Error('重发的通知应该是 3 条、编号和原来的一样，实际 ' + resent.length + ' 条');
      await admin.keyboard.press('Escape');
    });

    await flow.step('后台：重新生成密钥后旧的马上失效、新的能用；停用后新的也不能用；删除应用', admin, async () => {
      const row = el.row(admin, appName);
      await row.getByRole('button', { name: '重新生成密钥' }).click();
      await el.boxOk(admin);
      const fresh = await takeKey();
      if (fresh === key) throw new Error('重新生成的密钥应该和原来的不一样');
      expect(await call('GET', '/ping', key), 401, '旧密钥');
      expect(await call('GET', '/ping', fresh), 0, '新密钥');

      await row.locator('.el-switch').click();
      await el.message(admin, '已停用');
      expect(await call('GET', '/ping', fresh), 401, '停用后');

      await row.getByRole('button', { name: '删除' }).click();
      await el.boxOk(admin);
      await el.message(admin, '已删除');
      await row.waitFor({ state: 'detached' });
    });
  } finally {
    backend('openapi', { E2E_STEP: 'cleanup' });
    await adminCtx.close();
    await pc.context().close();
    await client.close();
    receiver.server.close();
  }

  return flow;
};
