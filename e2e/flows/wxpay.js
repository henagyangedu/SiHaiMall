const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, mobile, uniGo, uniLogin } = require('../lib/harness');

/**
 * 在微信里打开的 H5 用微信支付（手机端订单详情页的那段逻辑）：
 * 选微信支付 → 跳去微信确认付款人身份 → 带着 code 跳回本页 → 发起支付 → 调起微信收银台。
 * 微信那一侧全部是模拟的：浏览器标识里加上 MicroMessenger，支付相关的四个接口由测试直接应答，
 * 页面里放一个假的 WeixinJSBridge。所以这里验证的只是页面自己的逻辑，不是和微信的真实对接。
 */
module.exports = async function wxpayFlow(browser) {
  const flow = new Flow('wxpay');
  const made = backend('wxpay', { E2E_STEP: 'make' });
  const ctx = await browser.newContext({ ...mobile, userAgent: mobile.userAgent + ' MicroMessenger/8.0.50' });
  const page = flow.watch(await ctx.newPage(), '手机端（微信内）');
  const ok = (data) => ({ contentType: 'application/json', body: JSON.stringify({ code: 0, msg: 'ok', data }) });
  const seen = { oauthReturn: '', payBody: null };
  const detail = `/pages/order/detail?order_no=${made.order_no}`;

  // 假的微信收银台：记下页面传来的参数，然后当作买家点了取消
  await ctx.addInitScript(() => {
    window.WeixinJSBridge = { invoke: (name, params, cb) => { window.__wxpay = { name, params }; cb({ err_msg: 'get_brand_wcpay_request:cancel' }); } };
  });
  await ctx.route('**/api/orders/*/pay/channels', (r) => r.fulfill(ok([{ channel: 'wechat', name: '微信支付' }])));
  await ctx.route('**/api/pay/wechat/oauth-url*', (r) => {
    seen.oauthReturn = new URL(r.request().url()).searchParams.get('return_url') || '';
    // 真实情况下这里是微信的授权地址，微信确认身份后把 code 和 state 加在 # 前面跳回来；测试里直接给出“跳回来”的地址
    const back = new URL(seen.oauthReturn);
    r.fulfill(ok({ url: `${back.origin}${back.pathname}?code=E2E-OA-CODE&state=gmwxpay${back.hash}`, state: 'gmwxpay' }));
  });
  await ctx.route('**/api/orders/*/pay', (r) => {
    if (r.request().method() !== 'POST') return r.continue();
    seen.payBody = r.request().postDataJSON();
    r.fulfill(ok({ trade_no: 'PTE2EWXPAY', redirect_url: '', client_params: { appId: 'wxoa-e2e', timeStamp: '1', nonceStr: 'n', package: 'prepay_id=e2e', signType: 'RSA', paySign: 's' } }));
  });
  await ctx.route('**/api/pay/PTE2EWXPAY/confirm', (r) => r.fulfill(ok({ status: 'pending' })));

  try {
    await flow.step('微信内：订单详情点去支付，选微信支付后跳去确认付款人身份', page, async () => {
      await uniLogin(page);
      await uniGo(page, detail + '&pay=1');
      await page.locator('uni-actionsheet .uni-actionsheet__cell', { hasText: '微信支付' }).click();
      await page.waitForURL(/[?&]code=E2E-OA-CODE/);
      if (!seen.oauthReturn.includes('#' + detail)) throw new Error('交给微信的跳回地址不是当前订单页：' + seen.oauthReturn);
      if (/[?&]pay=1/.test(seen.oauthReturn)) throw new Error('跳回地址里不应该还带着 pay=1（否则回来又会弹一次支付方式）');
    });
    await flow.step('微信内：带着 code 跳回后自动发起支付并调起微信收银台', page, async () => {
      await page.waitForFunction(() => !!window.__wxpay);
      if (!seen.payBody || seen.payBody.channel !== 'wechat' || seen.payBody.wx_code !== 'E2E-OA-CODE') throw new Error('发起支付时没有带上微信给的 code：' + JSON.stringify(seen.payBody));
      const bridge = await page.evaluate(() => window.__wxpay);
      if (bridge.name !== 'getBrandWCPayRequest' || bridge.params.appId !== 'wxoa-e2e' || bridge.params.package !== 'prepay_id=e2e') throw new Error('调起收银台的参数不对：' + JSON.stringify(bridge));
    });
    await flow.step('微信内：code 用过后从地址里去掉，买家取消付款后订单仍是待支付、不再重复弹出', page, async () => {
      if (await page.evaluate(() => window.location.search) !== '') throw new Error('地址里还留着 code：' + page.url());
      await page.locator('.st', { hasText: '待支付' }).waitFor();
      await page.waitForTimeout(1500);
      if (await page.locator('uni-actionsheet .uni-actionsheet__cell').count()) throw new Error('回来后又弹出了支付方式');
      const calls = await page.evaluate(() => (window.__wxpay ? 1 : 0));
      if (calls !== 1) throw new Error('收银台没有被调起');
    });
  } finally {
    await ctx.close();
    backend('wxpay', { E2E_STEP: 'drop', E2E_ORDER: made.order_no });
  }

  return flow;
};
