const config = require('../lib/config');
const { Flow, Skip, el, desktop } = require('../lib/harness');

/** PC 端主流程：登录、搜索、加购、购物车、结算、下单、取消、售后、个人资料、语言币种、退出 */
module.exports = async function pcFlow(browser, data) {
  const flow = new Flow('pc');
  const page = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const go = (p) => page.goto(config.pc + '/#' + p);
  const step = (name, fn) => flow.step(name, page, fn);
  const title = data.single.title;

  await step('未登录访问订单页会被带到登录页', async () => {
    await go('/orders');
    await page.waitForURL(/#\/login\?redirect=/);
  });
  await step('密码错误有提示', async () => {
    await page.getByPlaceholder('邮箱或手机号').fill(config.account);
    await page.getByPlaceholder('密码').fill(config.password + '-wrong');
    await page.locator('.submit').click();
    await el.message(page, '账号或密码错误');
  });
  await step('登录成功后回到原来要去的订单页', async () => {
    await page.getByPlaceholder('密码').fill(config.password);
    await page.locator('.submit').click();
    await page.waitForURL(/#\/orders$/);
    await page.locator('.user', { hasText: '你好' }).waitFor();
  });

  await step('页头搜索能搜到商品', async () => {
    await page.getByPlaceholder('搜索商品').fill(title.slice(0, 2));
    await page.getByPlaceholder('搜索商品').press('Enter');
    await page.waitForURL(/keyword=/);
    await page.locator('.grid .card').first().waitFor();
  });
  await step('按价格排序会改地址并在升降序之间切换', async () => {
    await page.locator('.sort', { hasText: '价格' }).click();
    await page.waitForURL(/sort=price_asc/);
    await page.locator('.sort', { hasText: '价格' }).click();
    await page.waitForURL(/sort=price_desc/);
  });
  await step('点分类会筛选商品', async () => {
    // 分类是异步加载的，先等一会儿再判断有没有
    await page.locator('.cats .cat').nth(1).waitFor({ timeout: 5000 }).catch(() => {});
    if ((await page.locator('.cats .cat').count()) < 2) throw new Skip('没有商品分类');
    await page.locator('.cats .cat').nth(1).click();
    await page.waitForURL(/category_id=\d+/);
  });

  await step('单规格商品加入购物车', async () => {
    await go(`/goods/${data.single.goods_id}`);
    await page.getByRole('button', { name: '加入购物车' }).click();
    await el.message(page, '已加入购物车');
    await page.locator('.cart:not(.msg-link) .el-badge__content').waitFor();
  });
  await step('多规格商品不选规格不能加购，选齐后可以', async () => {
    if (!data.multi_goods_id) throw new Skip('开发库里没有多规格商品');
    await go(`/goods/${data.multi_goods_id}`);
    await page.locator('.value').first().waitFor();
    await page.getByRole('button', { name: '加入购物车' }).click();
    await el.message(page, '请选择规格');
    const rows = page.locator('.row:has(.values)');
    for (let i = 0; i < await rows.count(); i++) await rows.nth(i).locator('.value').first().click();
    await page.locator('.value.on').first().waitFor();
    await page.getByRole('button', { name: '加入购物车' }).click();
    await el.message(page, '已加入购物车');
  });

  await step('购物车：全选后合计不为 0，改数量会更新', async () => {
    await go('/cart');
    await page.locator('.table tbody tr').first().waitFor();
    await page.locator('.foot .el-checkbox').click();
    await page.waitForFunction(() => !document.querySelector('.total').textContent.includes('0.00'));
    const before = await page.locator('.total').textContent();
    await page.locator('.table tbody tr').first().locator('.el-input-number__increase').click();
    await page.waitForFunction((b) => document.querySelector('.total').textContent !== b, before);
  });
  await step('购物车：去结算', async () => {
    if (!(await page.locator('.foot .el-checkbox.is-checked').count())) await page.locator('.foot .el-checkbox').click();
    await page.getByRole('button', { name: /去结算/ }).click();
    await page.waitForURL(/#\/checkout\?cart_ids=/);
    await page.getByRole('button', { name: '提交订单' }).waitFor();
  });

  const addrName = '点击测试' + Date.now().toString().slice(-5);
  await step('结算页：新增收货地址并自动选中', async () => {
    await page.getByRole('button', { name: /新增地址/ }).click();
    const dlg = el.dialog(page);
    await dlg.locator('.el-form-item', { hasText: '收件人' }).locator('input').fill(addrName);
    await dlg.locator('.el-form-item', { hasText: '手机号' }).locator('input').fill('13900001111');
    await dlg.locator('.el-form-item', { hasText: '省/州' }).locator('.el-select').click();
    await el.option(page, '北京市').click();
    // 直辖市只有一个“市”，应该被自动带出
    await page.waitForFunction(() => [...document.querySelectorAll('.el-dialog .el-select')].filter((e) => e.textContent.includes('北京市')).length >= 2);
    await dlg.locator('.el-form-item', { hasText: '详细地址' }).locator('input').fill('点击测试路 1 号');
    await dlg.getByRole('button', { name: '保存' }).click();
    await el.message(page, '保存成功');
    await page.locator('.addr.on', { hasText: addrName }).waitFor();
  });
  await step('结算页：换一个地址后选中状态跟着变', async () => {
    const other = page.locator('.addr:not(.on)').first();
    const name = (await other.locator('.who').textContent()).trim().split(/\s/)[0];
    await other.click();
    await page.locator('.addr.on', { hasText: name }).waitFor();
  });
  let orderNo = '';
  await step('提交订单后进入订单详情并弹出支付方式', async () => {
    await page.locator('textarea').fill('点击测试订单');
    await page.getByRole('button', { name: '提交订单' }).click();
    await page.waitForURL(/#\/orders\/GM\w+/);
    orderNo = page.url().match(/orders\/(GM\w+)/)[1];
    const dlg = page.locator('.el-dialog:visible', { hasText: '选择支付方式' });
    await dlg.waitFor();
    // 没启用任何支付渠道时应该有说明，而不是空白
    if (!(await dlg.locator('.channel').count())) await dlg.getByText('暂时没有可用的支付方式').waitFor();
    await dlg.getByRole('button', { name: '取消' }).click();
  });
  await step('订单详情显示备注，可以取消订单', async () => {
    await page.locator('.st', { hasText: '待支付' }).waitFor();
    await page.getByText('点击测试订单').waitFor();
    await page.getByRole('button', { name: '取消订单' }).click();
    await el.boxOk(page);
    await page.locator('.st', { hasText: '已取消' }).waitFor();
  });
  await step('订单列表：页签筛选、进入详情', async () => {
    await go('/orders');
    await page.locator('.order').first().waitFor();
    await page.getByRole('tab', { name: '待收货' }).click();
    await page.waitForTimeout(600);
    await page.getByRole('tab', { name: '全部' }).click();
    await page.locator('.order', { hasText: orderNo }).getByRole('button', { name: '查看详情' }).click();
    await page.waitForURL(new RegExp(orderNo));
  });

  let refundNo = '';
  await step('已支付订单：申请整单退款并上传凭证图片', async () => {
    await go(`/orders/${data.paid_order_no}`);
    await page.getByRole('button', { name: '申请售后' }).click();
    await page.waitForURL(/\/refund$/);
    await page.locator('.opt').first().click();
    await page.locator('textarea').fill('点击测试：不想要了');
    await page.locator('input[type=file]').setInputFiles(data.image);
    await page.locator('.pic-img').waitFor();
    await page.getByRole('button', { name: '提交申请' }).click();
    await page.waitForURL(/#\/refunds\/RF\w+/);
    refundNo = page.url().match(/refunds\/(RF\w+)/)[1];
    await page.locator('.st', { hasText: '待审核' }).waitFor();
    await page.locator('.pics .pic').waitFor();
  });
  await step('售后列表能看到这张单，详情里可以撤销', async () => {
    await go('/refunds');
    await page.locator('.row', { hasText: refundNo }).click();
    await page.getByRole('button', { name: '撤销申请' }).click();
    await el.boxOk(page);
    await page.locator('.st', { hasText: '已撤销' }).waitFor();
  });

  await step('个人资料：改昵称并保存，页头同步更新，再改回来', async () => {
    await go('/profile');
    const input = page.locator('.el-form-item', { hasText: '昵称' }).locator('input');
    await input.waitFor();
    const old = await input.inputValue();
    await input.fill(old + '改');
    await page.getByRole('button', { name: '保存' }).click();
    await el.message(page, '保存成功');
    await page.locator('.user', { hasText: old + '改' }).waitFor();
    await input.fill(old);
    await page.getByRole('button', { name: '保存' }).click();
    await page.waitForFunction((o) => !document.querySelector('.user').textContent.includes(o + '改'), old);
  });
  await step('个人资料：上传头像后立即显示', async () => {
    await page.locator('input[type=file]').setInputFiles(data.image);
    await page.locator('img.avatar').waitFor();
  });
  await step('账户总览：编辑并删除刚才新增的地址', async () => {
    await go('/user');
    const row = page.locator('.addr', { hasText: addrName });
    await row.waitFor();
    await row.getByText('编辑').click();
    const dlg = el.dialog(page);
    await dlg.locator('.el-form-item', { hasText: '详细地址' }).locator('input').fill('点击测试路 2 号');
    await dlg.getByRole('button', { name: '保存' }).click();
    await el.message(page, '保存成功');
    await page.locator('.addr', { hasText: '点击测试路 2 号' }).waitFor();
    await page.locator('.addr', { hasText: addrName }).getByText('删除').click();
    await el.boxOk(page);
    await page.locator('.addr', { hasText: addrName }).waitFor({ state: 'detached' });
  });
  await step('积分明细、分销中心、帮助中心、协议、找回密码页都能打开', async () => {
    await go('/points');
    await page.locator('.balance .num').waitFor();
    // 积分下面显示会员等级和成长值，账户总览的昵称旁边有等级名称
    await page.locator('.balance .level', { hasText: '成长值' }).waitFor();
    await go('/user');
    await page.locator('.nick .level').waitFor();
    await go('/distribution');
    await page.locator('.panel-title', { hasText: '分销中心' }).waitFor();
    await go('/articles?type=help');
    await page.getByRole('tab', { name: '帮助中心' }).waitFor();
    await go('/protocols');
    await page.locator('.menu-title').waitFor();
    await go('/reset-password');
    await page.getByRole('button', { name: '重置密码' }).waitFor();
  });

  await step('切换到英文和另一种币种：界面文字和价格符号都跟着变，再换回来', async () => {
    await go(`/goods/${data.single.goods_id}`);
    await page.locator('.big-price').waitFor();
    const picks = page.locator('.top .pick');
    if ((await picks.count()) < 2) throw new Skip('只启用了一种语言或一种币种');
    // 当前的语言和币种存在浏览器本地（gm_lang / gm_currency），用它们判断切换是否生效、结束时是否要换回
    const saved = (key) => page.evaluate((k) => localStorage.getItem(k), key);
    const options = page.locator('.el-select-dropdown:visible .el-select-dropdown__item');
    const choose = async (i, text) => {
      await picks.nth(i).click();
      await options.first().waitFor(); // 下拉有展开动画，等选项出来再点
      await options.filter({ hasText: text }).first().click();
      await options.first().waitFor({ state: 'hidden' });
    };
    const currency = await saved('gm_currency');
    const symbol = (await page.locator('.big-price').textContent()).trim()[0];
    // 先看一眼有哪些币种，挑一个和当前不同的
    await picks.nth(1).click();
    await options.first().waitFor();
    const all = (await options.allTextContents()).map((x) => x.trim());
    await page.keyboard.press('Escape');
    await options.first().waitFor({ state: 'hidden' });
    const another = all.find((x) => !x.startsWith(currency));
    const back = all.find((x) => x.startsWith(currency));
    try {
      await choose(0, 'English');
      await page.getByRole('button', { name: 'Add to cart' }).waitFor();
      await choose(1, another);
      await page.waitForFunction((x) => document.querySelector('.big-price') && document.querySelector('.big-price').textContent.trim()[0] !== x, symbol);
    } finally {
      // 无论成败都换回来，免得把会员的语言和币种偏好留成测试值（它们会同步到会员资料）
      if ((await saved('gm_currency')) !== currency) await choose(1, back);
      if ((await saved('gm_lang')) !== 'zh-CN') await choose(0, '简体中文');
    }
    await page.getByRole('button', { name: '加入购物车' }).waitFor();
  });
  await step('收藏：商品详情点收藏，“我的收藏”里出现，移除后恢复', async () => {
    await go(`/goods/${data.single.goods_id}`);
    const fav = page.locator('.extras .fav');
    await fav.waitFor();
    // 上一轮如果中途失败可能留着收藏，先取消掉，保证从“未收藏”开始
    if (await page.locator('.extras .fav.on').count()) { await fav.click(); await page.locator('.extras .fav:not(.on)').waitFor(); }
    await fav.click();
    await page.locator('.extras .fav.on', { hasText: '已收藏' }).waitFor();
    // 从会员中心的菜单进“我的收藏”
    await go('/user');
    await page.locator('.menu .item', { hasText: '我的收藏' }).click();
    await page.waitForURL(/#\/favorites$/);
    const card = page.locator('.fav', { hasText: title });
    await card.waitFor();
    await card.locator('.price').waitFor();
    await card.locator('.remove').click();
    await card.waitFor({ state: 'detached' });
    await go(`/goods/${data.single.goods_id}`);
    await page.locator('.extras .fav:not(.on)', { hasText: '收藏' }).waitFor();
  });

  await step('清空购物车里测试加的商品', async () => {
    await go('/cart');
    await page.waitForTimeout(800);
    if (!(await page.locator('.table tbody tr').count())) return;
    if (!(await page.locator('.foot .el-checkbox.is-checked').count())) await page.locator('.foot .el-checkbox').click();
    await page.locator('.foot a.link', { hasText: '删除' }).click();
    await el.boxOk(page);
    await page.getByText('购物车是空的').waitFor();
  });
  await step('退出登录后页头变回“登录 / 注册”', async () => {
    await page.locator('.user').hover();
    await page.locator('.el-dropdown-menu__item', { hasText: '退出登录' }).click();
    await el.boxOk(page);
    await page.locator('.user', { hasText: '登录 / 注册' }).waitFor();
  });

  await page.context().close();
  return flow;
};
