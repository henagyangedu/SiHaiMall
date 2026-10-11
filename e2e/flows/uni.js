const config = require('../lib/config');
const { Flow, Skip, mobile, uniGo } = require('../lib/harness');

/** 手机端 H5 主流程（以手机尺寸打开）：登录、加购、分类、购物车、结算、下单、取消、物流、售后、资料、退出 */
module.exports = async function uniFlow(browser, data) {
  const flow = new Flow('uni');
  const page = flow.watch(await (await browser.newContext(mobile)).newPage(), '手机端');
  const go = (p) => uniGo(page, p);
  const step = (name, fn) => flow.step(name, page, fn);
  const toast = (text) => page.locator('uni-toast', { hasText: text }).first().waitFor();
  const modalOk = () => page.locator('uni-modal .uni-modal__btn_primary').click();
  const tap = (text) => page.getByText(text, { exact: true }).first().click();
  // 提示会停留约 2 秒；连续两次操作都要看提示时，等上一条消失
  const toastGone = () => page.waitForTimeout(2200);

  await step('个人中心点头部进入登录页，密码错误有提示', async () => {
    await go('/pages/user/index');
    await page.locator('.head').click();
    await page.locator('uni-input input').first().waitFor();
    await page.locator('uni-input input').nth(0).fill(config.account);
    await page.locator('uni-input input').nth(1).fill(config.password + '-wrong');
    await page.locator('.btn', { hasText: '登录' }).first().click();
    await toast('账号或密码错误');
  });
  await step('登录成功后回到个人中心并显示昵称和积分', async () => {
    await page.locator('uni-input input').nth(1).fill(config.password);
    await page.locator('.btn', { hasText: '登录' }).first().click();
    await page.locator('.nick', { hasText: data.nickname }).waitFor();
    await page.locator('.meta', { hasText: '积分' }).waitFor();
  });

  await step('首页显示商品，点商品进入详情并加入购物车', async () => {
    await go('/pages/index/index');
    await page.locator('.grid .item').first().waitFor();
    await go(`/pages/goods/detail?id=${data.single.goods_id}`);
    await page.locator('.price.big').waitFor();
    await tap('加入购物车');
    await toast('已加入购物车');
  });
  await step('多规格商品不选规格不能加购，选齐后可以', async () => {
    if (!data.multi_goods_id) throw new Skip('开发库里没有多规格商品');
    await go(`/pages/goods/detail?id=${data.multi_goods_id}`);
    await page.locator('.value').first().waitFor();
    await tap('加入购物车');
    await toast('请选择规格');
    const specs = page.locator('.spec');
    for (let i = 0; i < await specs.count(); i++) await specs.nth(i).locator('.value').first().click();
    await toastGone();
    await tap('加入购物车');
    await toast('已加入购物车');
  });
  await step('分类页能按分类和排序筛选', async () => {
    await go('/pages/category/index');
    // 左边只有一级分类；选中带下级的一级分类后，二级分类排在右边商品上方，默认选“全部”
    const tops = page.locator('.side .cat');
    const names = (await (await fetch(config.uni + '/api/categories')).json()).data;
    if ((await tops.count()) !== names.length + 1) throw new Error('左边应该只列一级分类（外加“全部商品”）');
    const withSub = names.findIndex((c) => (c.children || []).length);
    if (withSub >= 0) {
      await tops.nth(withSub + 1).click();
      const chips = page.locator('.subs .chip');
      await chips.nth(1).waitFor();
      if ((await chips.count()) !== names[withSub].children.length + 1) throw new Error('二级分类的个数不对');
      if (!(await page.locator('.subs .chip.on').innerText()).includes('全部')) throw new Error('默认应该选中“全部”');
      await chips.nth(1).click();
      await page.locator('.subs .chip.on', { hasText: names[withSub].children[0].name }).waitFor();
    } else if ((await tops.count()) > 1) await tops.nth(1).click();
    await page.locator('.sort', { hasText: '价格' }).click();
    await page.locator('.sort.on', { hasText: '↑' }).waitFor();
  });

  await step('购物车：全选后合计不为 0，改数量会更新', async () => {
    await go('/pages/cart/index');
    await page.locator('.row').first().waitFor();
    await page.locator('.bar .all').click();
    await page.waitForFunction(() => !document.querySelector('.bar .sum').textContent.includes('0.00'));
    const before = await page.locator('.bar .sum').textContent();
    await page.locator('.row').first().locator('.st').last().click();
    await page.waitForFunction((b) => document.querySelector('.bar .sum') && document.querySelector('.bar .sum').textContent !== b, before);
  });
  await step('购物车：去结算，结算页显示地址和金额', async () => {
    if (!(await page.locator('.bar .check.on').count())) await page.locator('.bar .all').click();
    await page.locator('.bar .go').click();
    await page.locator('.bar .go', { hasText: '提交订单' }).waitFor();
    await page.locator('.addr').waitFor();
    await page.getByText('商品金额').waitFor();
  });
  await step('结算页：从地址列表换一个地址', async () => {
    if (data.addresses < 2) throw new Skip('测试会员只有一个收货地址');
    await page.locator('.addr').click();
    const second = page.locator('.card .name').nth(1);
    const name = (await second.textContent()).trim();
    await second.click();
    await page.locator('.addr', { hasText: name }).waitFor();
    // 换回默认地址，保证后面一定能下单（别的地址可能不在配送范围）
    await page.locator('.addr').click();
    const first = page.locator('.card .name').first();
    const firstName = (await first.textContent()).trim();
    await first.click();
    await page.locator('.addr', { hasText: firstName }).waitFor();
  });
  let orderNo = '';
  await step('提交订单后进入订单详情并自动发起支付', async () => {
    await page.locator('.remark input').fill('点击测试订单');
    await page.locator('.bar .go').click();
    await page.waitForURL(/pages\/order\/detail\?order_no=GM\w+/);
    orderNo = page.url().match(/order_no=(GM\w+)/)[1];
    // 没启用支付渠道时弹出说明；启用了就弹出渠道选择
    const modal = page.locator('uni-modal', { hasText: '暂时没有可用的支付方式' });
    const sheet = page.locator('uni-actionsheet .uni-actionsheet__menu');
    await Promise.race([modal.waitFor(), sheet.waitFor()]);
    if (await modal.count()) await modalOk();
    else await page.locator('uni-actionsheet .uni-actionsheet__action').click();
  });
  await step('订单详情可以取消订单', async () => {
    await page.locator('.st', { hasText: '待支付' }).waitFor();
    await tap('取消订单');
    await modalOk();
    await page.locator('.st', { hasText: '已取消' }).waitFor();
  });
  await step('订单列表：页签筛选、进入详情', async () => {
    await go('/pages/order/list');
    await page.locator('.card').first().waitFor();
    await page.locator('.tab', { hasText: '待收货' }).click();
    await page.waitForTimeout(800);
    await page.locator('.tab', { hasText: '全部' }).click();
    await page.locator('.card', { hasText: orderNo }).first().click();
    await page.waitForURL(new RegExp(orderNo));
  });
  await step('已发货订单：查看物流', async () => {
    await go(`/pages/order/detail?order_no=${data.shipped_order_no}`);
    await page.locator('.track-btn').click();
    await page.locator('.tstatus').waitFor();
    // 开通了物流查询就有轨迹或状态；没开通、快递公司不支持时要有说明
    const text = await page.locator('.parcel').first().textContent();
    if (!/没有开通|不支持|暂时|运输中|已签收|异常/.test(text)) throw new Error('没有物流状态或说明：' + text.slice(0, 80));
  });

  await step('已支付订单：申请整单退款并上传凭证图片', async () => {
    await go(`/pages/order/detail?order_no=${data.paid_order_no}`);
    await tap('申请售后');
    await page.locator('.opt').first().waitFor();
    await page.locator('.opt').first().click();
    await page.locator('uni-textarea textarea').fill('点击测试：不想要了');
    const chooser = page.waitForEvent('filechooser');
    await page.locator('.pic-add').click();
    await (await chooser).setFiles(data.image);
    await page.locator('.pic-img').waitFor();
    await page.locator('.submit').click();
    await page.waitForURL(/pages\/refund\/detail\?refund_no=RF\w+/);
    await page.locator('.st', { hasText: '待审核' }).waitFor();
    await page.locator('.pics .pic').waitFor();
  });
  await step('售后详情可以撤销申请，售后列表能看到', async () => {
    await page.locator('.cancel').click();
    await modalOk();
    await page.locator('.st', { hasText: '已撤销' }).waitFor();
    await go('/pages/refund/list');
    await page.locator('.card .status', { hasText: '已撤销' }).first().waitFor();
  });

  await step('个人资料：改昵称并保存，再改回来', async () => {
    await go('/pages/user/profile');
    const input = page.locator('uni-input input').first();
    await input.waitFor();
    const old = await input.inputValue();
    await input.fill(old + '改');
    await page.locator('.save').click();
    await toast('保存成功');
    await toastGone();
    await input.fill(old);
    await page.locator('.save').click();
    await toast('保存成功');
  });
  await step('新增收货地址：国内地址不选省市会被拦住', async () => {
    // 省、市是滚轮选择器，自动化里不好操作，这里只验证必填校验
    await go('/pages/address/edit');
    const inputs = page.locator('uni-input input');
    await inputs.first().waitFor();
    await inputs.nth(0).fill('点击测试');
    await inputs.nth(1).fill('13900002222');
    await page.locator('.btn', { hasText: '保存' }).click();
    await toast('请选择省市');
  });
  await step('积分明细、收货地址、分销中心、帮助中心、协议、资讯都能打开', async () => {
    await go('/pages/user/points');
    await page.locator('.head .num').waitFor();
    // 积分下面显示会员等级和成长值
    await page.locator('.head .level', { hasText: '成长值' }).waitFor();
    for (const p of ['/pages/address/list', '/pages/distribution/index', '/pages/article/list?type=help', '/pages/protocol/index', '/pages/article/list?type=article']) {
      await go(p);
      await page.locator('uni-page-body').waitFor();
    }
  });
  await step('收藏：商品详情点收藏，个人中心的“我的收藏”里出现，移除后列表为空', async () => {
    await go(`/pages/goods/detail?id=${data.single.goods_id}`);
    const fav = page.locator('.bar .fav');
    await fav.waitFor();
    // 上一轮如果中途失败可能留着收藏，先取消掉，保证从“未收藏”开始
    if (await page.locator('.bar .fav.on').count()) { await fav.click(); await page.locator('.bar .fav:not(.on)').waitFor(); }
    // 按钮是先变样子、再发请求的：等收藏的请求回来再去列表页
    await Promise.all([
      page.waitForResponse((r) => /\/api\/favorites\/\d+/.test(r.url()) && r.request().method() === 'POST'),
      fav.click(),
    ]);
    await page.locator('.bar .fav.on', { hasText: '已收藏' }).waitFor();
    await go('/pages/user/index');
    await page.locator('.cell', { hasText: '我的收藏' }).click();
    const row = page.locator('.row', { hasText: data.single.title });
    await row.waitFor();
    await row.locator('.price').waitFor();
    await row.locator('.remove').click();
    await row.waitFor({ state: 'detached' });
    await go(`/pages/goods/detail?id=${data.single.goods_id}`);
    await page.locator('.bar .fav:not(.on)').waitFor();
  });

  await step('清空购物车里测试加的商品', async () => {
    await go('/pages/cart/index');
    await page.waitForTimeout(1000);
    if (!(await page.locator('.row').count())) return;
    if (!(await page.locator('.bar .check.on').count())) await page.locator('.bar .all').click();
    await page.locator('.bar .del').click();
    await modalOk();
    await page.getByText('购物车是空的').waitFor();
  });
  await step('退出登录', async () => {
    await go('/pages/user/index');
    await page.locator('.logout').click();
    await modalOk();
    await page.locator('.nick', { hasText: '点击登录' }).waitFor();
  });

  await page.context().close();
  return flow;
};
