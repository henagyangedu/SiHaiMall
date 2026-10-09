const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, Skip, el, desktop, mobile, pcLogin, uniGo, uniLogin } = require('../lib/harness');

/**
 * 账号与安全，跨 PC 端和手机端：
 * 一个没有密码、没有邮箱和手机号的会员（微信一键登录注册出来的就是这样）
 * 设置密码 → 修改密码 → 绑定邮箱 → 换一个浏览器用邮箱和密码登录 → 在手机端绑定手机号。
 * 会员是现造的，结束时注销，不占库存。
 */
module.exports = async function securityFlow(browser) {
  const flow = new Flow('security');
  const made = backend('security', { E2E_STEP: 'make' });
  const ctx = await browser.newContext(desktop);
  // 直接带着令牌进入，相当于这个会员已经登录
  await ctx.addInitScript((t) => localStorage.setItem('gm_token', t), made.token);
  const pc = flow.watch(await ctx.newPage(), 'PC');
  const row = (name) => pc.locator(`.row[data-row="${name}"]`);

  const stamp = Date.now().toString().slice(-8);
  const email = `e2e-bind${stamp}@example.com`;
  const phone = '139' + stamp;
  const pwd1 = 'First#' + stamp;
  const pwd2 = 'Second#' + stamp;

  try {
    await flow.step('PC：会员中心菜单进入“账号与安全”，密码、邮箱、手机号都是空的', pc, async () => {
      await pc.goto(config.pc + '/#/user');
      await pc.locator('.menu .item', { hasText: '账号与安全' }).click();
      await pc.waitForURL(/#\/security$/);
      await row('pwd').getByText('未设置').waitFor();
      if ((await pc.locator('.row').filter({ hasText: '未绑定' }).count()) !== 2) throw new Error('邮箱和手机号应该都显示未绑定');
    });
    await flow.step('PC：第一次设置密码不用原密码，两次输入不一致会被拦住', pc, async () => {
      await row('pwd').getByRole('button', { name: '设置' }).click();
      if (await row('pwd').getByPlaceholder('原密码').count()) throw new Error('还没有密码时不应该要求原密码');
      await row('pwd').getByPlaceholder('新密码（至少 6 位）').fill(pwd1);
      await row('pwd').getByPlaceholder('再输入一次新密码').fill(pwd1 + 'x');
      await row('pwd').getByRole('button', { name: '保存' }).click();
      await el.message(pc, '两次输入的密码不一致');
      await row('pwd').getByPlaceholder('再输入一次新密码').fill(pwd1);
      await row('pwd').getByRole('button', { name: '保存' }).click();
      await el.message(pc, '密码已保存');
      await row('pwd').getByText('已设置').waitFor();
    });
    await flow.step('PC：再改密码必须提供原密码，原密码不对会被拒绝', pc, async () => {
      await row('pwd').getByRole('button', { name: '修改' }).click();
      await row('pwd').getByPlaceholder('新密码（至少 6 位）').fill(pwd2);
      await row('pwd').getByPlaceholder('再输入一次新密码').fill(pwd2);
      await row('pwd').getByRole('button', { name: '保存' }).click();
      await el.message(pc, '请输入原密码');
      await row('pwd').getByPlaceholder('原密码').fill('wrong-' + stamp);
      await row('pwd').getByRole('button', { name: '保存' }).click();
      await el.message(pc, '原密码不正确');
      await row('pwd').getByPlaceholder('原密码').fill(pwd1);
      await row('pwd').getByRole('button', { name: '保存' }).click();
      // 上一步的“密码已保存”提示可能还没消失，所以以表单收起为准
      await row('pwd').getByPlaceholder('原密码').waitFor({ state: 'detached' });
    });
    await flow.step('PC：绑定邮箱，格式不对和别人在用的邮箱都绑不了，绑定后不能再改', pc, async () => {
      if (made.email_code) throw new Skip('开启了邮箱验证码，绑定要收邮件');
      await row('email').getByRole('button', { name: '绑定' }).click();
      await row('email').getByPlaceholder('邮箱地址').fill('not-an-email');
      await row('email').getByRole('button', { name: '绑定' }).last().click();
      await el.message(pc, '请输入正确的邮箱');
      await row('email').getByPlaceholder('邮箱地址').fill(config.account);
      await row('email').getByRole('button', { name: '绑定' }).last().click();
      await el.message(pc, '该邮箱已被其他账号使用');
      await row('email').getByPlaceholder('邮箱地址').fill(email);
      await row('email').getByRole('button', { name: '绑定' }).last().click();
      await el.message(pc, '绑定成功');
      await row('email').getByText(email).waitFor();
      if (await row('email').getByRole('button').count()) throw new Error('绑定后不应该还有绑定按钮');
    });
    await flow.step('PC：换一个浏览器，用刚绑定的邮箱和新密码能登录，旧密码不行', pc, async () => {
      if (made.email_code) throw new Skip('邮箱没有绑定');
      const other = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC 新浏览器');
      try {
        await other.goto(config.pc + '/#/login');
        await other.getByPlaceholder('邮箱或手机号').fill(email);
        await other.getByPlaceholder('密码').fill(pwd1);
        await other.locator('.submit').click();
        await el.message(other, '账号或密码错误');
        await pcLogin(other, email, pwd2);
        await other.goto(config.pc + '/#/profile');
        await other.getByText(made.account).waitFor();
      } finally {
        await other.context().close();
      }
    });
    let phoneBound = false;
    await flow.step('手机端：用邮箱登录，“账号与安全”显示一致，并绑定手机号（要短信验证码时只核对表单）', pc, async () => {
      if (made.email_code) throw new Skip('邮箱没有绑定，无法在手机端登录');
      const mctx = await browser.newContext(mobile);
      const m = flow.watch(await mctx.newPage(), '手机端');
      try {
        await uniLogin(m, email, pwd2);
        await uniGo(m, '/pages/user/index');
        await m.locator('.cell', { hasText: '账号与安全' }).click();
        await m.locator('.cell', { hasText: '登录密码' }).getByText('已设置').waitFor();
        await m.locator('.cell', { hasText: '邮箱' }).getByText(email).waitFor();
        await m.locator('.cell', { hasText: '手机号' }).click();
        await m.locator('.form .btn', { hasText: '绑定' }).click();
        await m.locator('uni-toast', { hasText: '请输入正确的手机号' }).waitFor();
        await m.locator('.form uni-input input').first().fill(phone);
        if (made.sms_code) {
          // 启用了短信：要先收验证码，点击测试到此为止，只确认验证码输入框和按钮出现了
          await m.locator('.form .code-btn', { hasText: '获取验证码' }).waitFor();
          return;
        }
        await m.locator('.form .btn', { hasText: '绑定' }).click();
        await m.locator('.cell', { hasText: '手机号' }).getByText(phone).waitFor();
        phoneBound = true;
      } finally {
        await mctx.close();
      }
    });
    await flow.step('PC：刷新后手机号也显示为已绑定', pc, async () => {
      if (!phoneBound) throw new Skip('手机号没有绑定（开启了验证码）');
      await pc.reload();
      await row('phone').getByText(phone).waitFor();
    });
  } finally {
    backend('security', { E2E_STEP: 'drop', E2E_MEMBER: String(made.member_id) });
    await ctx.close();
  }

  return flow;
};
