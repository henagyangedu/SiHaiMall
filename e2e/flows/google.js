const http = require('http');
const crypto = require('crypto');
const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, Skip, el, desktop, mobile, uniGo } = require('../lib/harness');

/**
 * Google 登录（后台、PC 端、手机端）。**Google 一侧全是模拟的**：
 * - 流程现场生成一对密钥，在本机起一个假的“Google 公钥接口”，后台配置里的公钥地址指向它（所以要求后端能访问到本机的 127.0.0.1）；
 * - 浏览器里把 Google 的登录脚本换成一个假的：画一个按钮，点了就把流程签发的身份凭证交给页面。
 * 所以这里验证的是商城自己的逻辑：按钮何时出现、第一次登录要先同意协议、自动注册、再次登录、伪造的凭证被拒。和真实 Google 的对接没有验证。
 * 开发库里已经有真实的 Google 登录配置时整套跳过。跑完删掉测试建的配置和注册出来的会员。
 */
const b64 = (x) => Buffer.from(x).toString('base64url');

module.exports = async function googleFlow(browser) {
  const flow = new Flow('google');
  const { publicKey, privateKey } = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
  const other = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
  const jwk = { ...publicKey.export({ format: 'jwk' }), kid: 'e2e-key', alg: 'RS256', use: 'sig' };
  const server = http.createServer((req, res) => {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ keys: [jwk] }));
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const made = backend('google', { E2E_VALUE: `http://127.0.0.1:${server.address().port}/certs` });
  const skipIfReal = () => { if (made.skip) throw new Skip('开发库里已经有真实的 Google 登录配置，不去动它'); };
  const NAME = 'E2E Google 买家';

  /** 签发一个身份凭证；key 不传就用“Google 的私钥”，传别的私钥就是伪造的 */
  const credential = (key = privateKey) => {
    const now = Math.floor(Date.now() / 1000);
    const input = b64(JSON.stringify({ alg: 'RS256', kid: 'e2e-key', typ: 'JWT' })) + '.' + b64(JSON.stringify({
      iss: 'https://accounts.google.com', aud: made.client_id, sub: made.sub, email: 'e2e-google@example.com', email_verified: true, name: NAME, iat: now - 5, exp: now + 3600,
    }));
    return input + '.' + crypto.sign('RSA-SHA256', Buffer.from(input), key).toString('base64url');
  };
  // 假的 Google 登录脚本：按钮点下去，把 window.__gmCredential 交给页面
  const fakeScript = `window.google = { accounts: { id: {
    initialize(o) { window.__gmGoogle = o; },
    renderButton(el, o) { window.__gmGoogleOptions = o; el.innerHTML = '<button type="button" class="fake-google">Continue with Google</button>';
      el.firstChild.onclick = () => window.__gmGoogle.callback({ credential: window.__gmCredential }); },
  } } };`;
  const withFakeGoogle = async (ctx, cred) => {
    await ctx.route('https://accounts.google.com/gsi/client', (route) => route.fulfill({ contentType: 'application/javascript', body: fakeScript }));
    await ctx.addInitScript((c) => { window.__gmCredential = c; }, cred);
  };

  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  if (!made.skip) await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), made.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');
  const pcCtx = await browser.newContext(desktop);
  const pc = flow.watch(await pcCtx.newPage(), 'PC');
  const badCtx = await browser.newContext(desktop);
  const bad = flow.watch(await badCtx.newPage(), 'PC（伪造）');
  const mctx = await browser.newContext(mobile);
  const m = flow.watch(await mctx.newPage(), '手机端');
  const needAgree = made.skip ? false : (await (await fetch(config.pc + '/api/config/init')).json()).data.agree_required;

  try {
    await flow.step('后台：“应用 → 第三方登录”里有 Google 登录，已配置并开启；配置弹窗里看得到客户端 ID', admin, async () => {
      skipIfReal();
      await admin.goto(config.admin + '/app/oauth');
      const r = el.row(admin, 'Google 登录');
      await r.getByText('已配置').waitFor();
      await r.getByRole('button', { name: '配置' }).click();
      const d = el.dialog(admin);
      const shown = await d.locator('.el-form-item', { hasText: '客户端 ID' }).locator('input').inputValue();
      if (shown !== made.client_id) throw new Error('客户端 ID 应该原样显示（它不是密钥），实际是：' + shown);
      await d.getByRole('button', { name: '取消' }).click();
    });

    await flow.step('PC：伪造的凭证（不是 Google 的私钥签的）登录不了，提示凭证无效', bad, async () => {
      skipIfReal();
      await withFakeGoogle(badCtx, credential(other.privateKey));
      await bad.goto(config.pc + '/#/login');
      await bad.locator('.fake-google').click();
      await el.message(bad, 'Google 登录凭证无效，请重试');
      if (await bad.evaluate(() => localStorage.getItem('gm_token'))) throw new Error('伪造的凭证不应该登录成功');
    });

    await flow.step('PC：登录页有 Google 登录按钮；第一次登录先要同意协议，同意后自动注册并登录，页头显示 Google 账号的名字', pc, async () => {
      skipIfReal();
      await withFakeGoogle(pcCtx, credential());
      await pc.goto(config.pc + '/#/login');
      await pc.locator('.fake-google').waitFor();
      const opts = await pc.evaluate(() => ({ client: window.__gmGoogle.client_id, locale: window.__gmGoogleOptions.locale }));
      if (opts.client !== made.client_id || opts.locale !== 'zh-CN') throw new Error('交给 Google 脚本的客户端 ID 或语言不对：' + JSON.stringify(opts));
      await pc.locator('.fake-google').click();
      if (needAgree) {
        const box = pc.locator('.google-agree');
        await box.getByText('第一次用 Google 登录会为你创建一个新账号').waitFor();
        if (!(await box.locator('a.link').count())) throw new Error('同意协议的提示里应该有协议的链接');
        await box.getByRole('button', { name: '同意并继续' }).click();
      }
      await pc.locator('.user', { hasText: NAME }).waitFor();
    });

    await flow.step('PC：这个会员没有密码，“账号与安全”里能看到 Google 给的邮箱；退出后再用 Google 登录，直接进来、不再问协议', pc, async () => {
      skipIfReal();
      await pc.goto(config.pc + '/#/security');
      await pc.getByText('e2e-google@example.com').first().waitFor();
      await pc.getByText('未设置').first().waitFor();
      await pc.locator('.user', { hasText: NAME }).hover();
      await pc.locator('.el-dropdown-menu__item', { hasText: '退出登录' }).click();
      await el.boxOk(pc);
      await pc.locator('.user', { hasText: '登录 / 注册' }).waitFor();
      await pc.goto(config.pc + '/#/login');
      await pc.locator('.fake-google').click();
      await pc.locator('.user', { hasText: NAME }).waitFor();
      if (await pc.locator('.google-agree').count()) throw new Error('已经注册过的 Google 账号不应该再问协议');
    });

    await flow.step('手机端：登录页同样有 Google 登录按钮，同一个 Google 账号登录后是同一个会员', m, async () => {
      skipIfReal();
      await withFakeGoogle(mctx, credential());
      await uniGo(m, '/pages/login/index');
      await m.locator('.fake-google').click();
      await m.locator('uni-toast').first().waitFor();
      await uniGo(m, '/pages/user/index');
      await m.getByText(NAME).first().waitFor();
    });
  } finally {
    if (!made.skip) backend('google', { E2E_STEP: 'restore' });
    server.close();
    await adminCtx.close();
    await pcCtx.close();
    await badCtx.close();
    await mctx.close();
  }

  return flow;
};
