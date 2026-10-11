const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, el, desktop, pcLogin } = require('../lib/harness');

/**
 * 开放接口（后台页面 + 直接调后端接口 + PC 端）：
 * 后台“设置 → 开放接口”新建一个只有订单和发货权限的应用，密钥只显示一次 → 用这把密钥调接口：连通测试、没权限的被拒、拉到刚付款的订单
 * → 用接口给这张订单发货，重复提交不会发两次 → 买家在 PC 端看到已发货和快递单号 → 后台的调用记录里有这次发货
 * → 重新生成密钥后旧的失效；停用后新的也不能用 → 删除应用。
 * 每遍造一张已付款订单并把它发货，占 1 件库存；应用是测试自己建、自己删的。
 * 接口是直接请求后端的（地址取自后台开发服务器的转发目标，见 lib/config.js 的 api）。
 */
module.exports = async function openapiFlow(browser) {
  const flow = new Flow('openapi');
  const made = backend('openapi');
  const appName = '点击测试 ERP';
  const expressNo = 'E2E' + Date.now();
  let key = '';

  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), made.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');
  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  // 直接请求后端。本机的后端用的是自签名证书
  const http = await browser.newContext({ ignoreHTTPSErrors: true });

  /** 调开放接口，返回 {code, msg, data} */
  async function call(method, path, k, data) {
    const res = await http.request.fetch(`${config.api}/open-api/v1${path}`, {
      method, headers: { Accept: 'application/json', ...(k ? { Authorization: `Bearer ${k}` } : {}) },
      ...(method === 'GET' ? { params: data } : { data }),
    });
    const text = await res.text();
    try { return JSON.parse(text); } catch (e) { throw new Error(`${method} ${path} 返回的不是 JSON（HTTP ${res.status()}）：${text.slice(0, 200)}`); }
  }
  const expect = (res, code, what) => { if (res.code !== code) throw new Error(`${what}：应该返回 ${code}，实际是 ${res.code} ${res.msg}`); };
  /** 读出“请保存密钥”弹窗里的密钥并关掉弹窗 */
  async function takeKey() {
    const d = el.dialog(admin);
    await d.getByText('密钥只显示这一次').waitFor();
    const value = await d.locator('.key-input input').inputValue();
    if (!/^shm_[A-Za-z0-9]{40}$/.test(value)) throw new Error('密钥的样子不对：' + value);
    await d.getByRole('button', { name: '我已保存' }).click();
    await d.waitFor({ state: 'hidden' });
    return value;
  }

  try {
    await flow.step('后台：新建应用——不填名称、不选权限都被拦住；只勾“读取订单”和“发货”，创建后密钥只显示一次，列表里只有开头几位', admin, async () => {
      await admin.goto(config.admin + '/setting/open-api');
      await admin.getByRole('button', { name: '新建应用' }).click();
      const d = el.dialog(admin);
      await d.getByRole('button', { name: '创建并生成密钥' }).click();
      await el.message(admin, '请填写应用名称');
      await d.getByPlaceholder('对方系统的名称').fill(appName);
      await d.getByRole('button', { name: '创建并生成密钥' }).click();
      await el.message(admin, '请至少选择一项权限');
      await d.locator('.el-checkbox', { hasText: '读取订单' }).click();
      await d.locator('.el-checkbox', { hasText: '发货、修改物流单号' }).click();
      await d.getByPlaceholder('选填，如对接人、用途').fill('点击测试建的，可以删');
      await d.getByRole('button', { name: '创建并生成密钥' }).click();
      key = await takeKey();

      const row = el.row(admin, appName);
      await row.waitFor();
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
    await http.close();
  }

  return flow;
};
