const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, desktop, mobile, uniGo } = require('../lib/harness');

/**
 * 界面语言（PC 端和手机端，不用登录、不造订单）：
 * 临时启用西班牙语、法语、德语、日语、俄语 → PC 端页头的语言下拉里选日语，页头、商品详情的文字变成日文，组件库自带的文字也跟着变
 * → 依次换成另外四种语言 → 接口返回的提示这几种语言还是英文 → 手机端用日语打开，底部导航和个人中心是日文。
 * 结束时把这五种语言恢复成运行前的状态（原来没有的会留在语言表里，禁用）。
 */
module.exports = async function i18nFlow(browser) {
  const flow = new Flow('i18n');
  const made = backend('i18n');
  const goods = { id: made.goods_id };

  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const mctx = await browser.newContext(mobile);
  const m = flow.watch(await mctx.newPage(), '手机端');

  const options = pc.locator('.el-select-dropdown:visible .el-select-dropdown__item');
  const choose = async (name) => {
    await pc.locator('.top .pick').first().click();
    await options.first().waitFor();
    await options.filter({ hasText: name }).first().click();
    await options.first().waitFor({ state: 'hidden' });
  };
  const htmlLang = () => pc.evaluate(() => document.documentElement.lang);

  try {
    await flow.step('PC：语言下拉里有新加的五种语言；选日语，页头和商品详情变成日文，网页的语言标记也跟着变', pc, async () => {
      await pc.goto(`${config.pc}/#/goods/${goods.id}`);
      await pc.getByRole('button', { name: '加入购物车' }).waitFor();
      await pc.locator('.top .pick').first().click();
      await options.first().waitFor();
      const all = (await options.allTextContents()).map((x) => x.trim());
      await pc.keyboard.press('Escape');
      await options.first().waitFor({ state: 'hidden' });
      for (const name of Object.values(made.names)) if (!all.includes(name)) throw new Error(`语言下拉里没有 ${name}：${all.join('、')}`);

      await choose(made.names.ja);
      await pc.getByRole('button', { name: 'カートに入れる' }).waitFor();
      await pc.getByRole('button', { name: '今すぐ購入' }).waitFor();
      await pc.locator('.user', { hasText: 'ログイン / 新規登録' }).waitFor();
      if ((await htmlLang()) !== 'ja') throw new Error('网页的语言标记应该是 ja，实际是 ' + (await htmlLang()));
      // 切到首页：区块标题（PC 端独有的文案）也是日文
      await pc.goto(config.pc + '/#/');
      await pc.getByText('暮らしのシーンから選ぶ').first().waitFor();
    });

    await flow.step('PC：依次换成西班牙语、法语、俄语、德语，商品详情的按钮跟着变', pc, async () => {
      await pc.goto(`${config.pc}/#/goods/${goods.id}`);
      for (const [code, text] of [['es', 'Añadir al carrito'], ['fr', 'Ajouter au panier'], ['ru', 'В корзину'], ['de', 'In den Warenkorb']]) {
        await choose(made.names[code]);
        await pc.getByRole('button', { name: text }).waitFor();
        if ((await htmlLang()) !== code) throw new Error(`网页的语言标记应该是 ${code}`);
      }
    });

    await flow.step('PC：德语界面下用错的密码登录，表单是德文，接口返回的提示是英文（这几种语言的接口提示还没翻译）', pc, async () => {
      await pc.goto(config.pc + '/#/login');
      await pc.getByPlaceholder('E-Mail oder Telefon').fill('nobody-e2e@example.com');
      await pc.getByPlaceholder('Passwort').fill('wrong-password');
      await pc.locator('.submit').click();
      // 正常是“账号或密码错误”的英文；短时间内跑了很多遍时会是限流的提示，同样应该是英文
      const tip = pc.locator('.el-message').first();
      await tip.waitFor();
      const text = (await tip.innerText()).trim();
      if (!text || /[一-鿿]/.test(text)) throw new Error('接口提示应该是英文，实际是：' + text);
    });

    await flow.step('手机端：用日语打开，底部导航、首页搜索框、个人中心都是日文', m, async () => {
      await mctx.addInitScript(() => { try { localStorage.setItem('gm_lang', 'ja'); } catch (e) { /* 空白页上不让用本地存储，不用管 */ } });
      await uniGo(m, '/pages/index/index');
      await m.locator('.uni-tabbar__label', { hasText: 'ホーム' }).first().waitFor();
      await m.locator('.uni-tabbar__label', { hasText: 'カート' }).first().waitFor();
      await uniGo(m, '/pages/user/index');
      await m.getByText('注文履歴').first().waitFor();
      await m.locator('.cell .sub', { hasText: made.names.ja }).first().waitFor();
    });

    await flow.step('PC：换回简体中文，文字恢复', pc, async () => {
      await pc.goto(`${config.pc}/#/goods/${goods.id}`);
      await choose('简体中文');
      await pc.getByRole('button', { name: '加入购物车' }).waitFor();
      if ((await htmlLang()) !== 'zh-CN') throw new Error('网页的语言标记应该回到 zh-CN');
    });
  } finally {
    backend('i18n', { E2E_STEP: 'restore', E2E_VALUE: JSON.stringify(made.original) });
    await pc.context().close();
    await mctx.close();
  }

  return flow;
};
