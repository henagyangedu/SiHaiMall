const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, el, desktop, mobile, pcLogin, uniGo, uniLogin } = require('../lib/harness');

/**
 * 搭配套餐（后台、PC 端、手机端）：
 * 后台新建套餐（只选一件、立减太大都被拦住）→ PC 端商品页出现“搭配套餐”，点“购买套餐”到结算页看到套餐优惠，下单后订单详情也有
 * → 手机端另一件商品的详情页也有这个套餐，结算页金额一致 → 后台订单详情显示用了哪个套餐；停用后商品页不再显示，再启用。
 * 套餐留在开发库里当例子（下次运行时先删掉再建）；PC 端下的那张订单在流程里取消，不占库存。
 */
module.exports = async function bundleFlow(browser) {
  const flow = new Flow('bundle');
  const made = backend('bundle');
  const { a, b } = made;
  const name = `示例套餐：${a.title.slice(0, 12)} + ${b.title.slice(0, 12)}`;
  const reduce = 10;
  const total = (Number(a.price) + Number(b.price)).toFixed(2);
  const price = (Number(a.price) + Number(b.price) - reduce).toFixed(2);
  let orderNo = '';

  const adminCtx = await browser.newContext({ viewport: { width: 1500, height: 950 }, locale: 'zh-CN' });
  await adminCtx.addInitScript((t) => localStorage.setItem('gm_admin_token', t), made.admin_token);
  const admin = flow.watch(await adminCtx.newPage(), '后台');
  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const mctx = await browser.newContext(mobile);
  const m = flow.watch(await mctx.newPage(), '手机端');

  // 新建弹窗里搜索商品并点选它的规格
  async function pickSku(d, title) {
    await d.getByPlaceholder('按商品名称或货号搜索').fill(title);
    await d.getByRole('button', { name: '搜索' }).click();
    const option = d.locator('.option', { hasText: title }).first();
    await option.click();
    await d.locator('.picked-row', { hasText: title }).waitFor();
  }

  try {
    await flow.step('后台：新建套餐——只选一件、立减不小于合计都被拦住；选两件、立减 10 后列表显示套餐价', admin, async () => {
      await admin.goto(config.admin + '/marketing/bundle');
      await admin.getByRole('button', { name: '新建套餐' }).click();
      const d = el.dialog(admin);
      await d.getByPlaceholder('只在后台显示').fill(name);
      await pickSku(d, a.title);
      await d.locator('.el-input-number input').fill(String(reduce));
      await d.getByRole('button', { name: '保存' }).click();
      await el.message(admin, '至少选 2 件商品');
      await pickSku(d, b.title);
      await d.getByText(`原价合计 ${total}`).waitFor();
      await d.locator('.el-input-number input').fill(total);
      await d.getByRole('button', { name: '保存' }).click();
      await el.message(admin, '立减金额必须小于这组商品的售价合计');
      await d.locator('.el-input-number input').fill(String(reduce));
      await d.locator('.el-input-number input').blur();
      await d.getByText(`套餐价 ${price}`).waitFor();
      await d.getByRole('button', { name: '保存' }).click();
      await el.message(admin, '保存成功');
      const row = el.row(admin, name);
      await row.getByText(`套餐价 ${price}`).waitFor();
      await row.getByText('进行中').waitFor();
    });
    await flow.step('PC：商品页出现“搭配套餐”，原价合计和套餐价都对；点“购买套餐”到结算页，有套餐优惠这一行', pc, async () => {
      await pcLogin(pc);
      await pc.goto(`${config.pc}/#/goods/${a.goods_id}`);
      const box = pc.locator('.bundles .bundle').first();
      await box.getByText(b.title).waitFor();
      await box.locator('.b-side').getByText(total).waitFor();
      await box.locator('.b-price', { hasText: price }).waitFor();
      await box.getByText(`立省 ¥${reduce.toFixed(2)}`).waitFor();
      await box.getByRole('button', { name: '购买套餐' }).click();
      await pc.waitForURL(/#\/checkout\?sku_ids=/);
      await pc.locator('.item', { hasText: a.title }).waitFor();
      await pc.locator('.item', { hasText: b.title }).waitFor();
      await pc.locator('.bundle-row', { hasText: `-¥${reduce.toFixed(2)}` }).waitFor();
    });
    await flow.step('PC：提交订单，订单详情里有“套餐优惠”，应付就是套餐价（加运费）；然后取消订单', pc, async () => {
      await pc.getByRole('button', { name: '提交订单' }).click();
      await pc.waitForURL(/#\/orders\/GM\w+/);
      orderNo = pc.url().match(/orders\/(GM\w+)/)[1];
      const dlg = pc.locator('.el-dialog:visible', { hasText: '选择支付方式' });
      await dlg.waitFor();
      await dlg.getByRole('button', { name: '取消' }).click();
      const sum = pc.locator('.sum');
      await sum.locator('.kv', { hasText: '套餐优惠' }).getByText(`-¥${reduce.toFixed(2)}`).waitFor();
      await sum.locator('.kv', { hasText: '商品金额' }).getByText(`¥${total}`).waitFor();
      await pc.getByRole('button', { name: '取消订单' }).click();
      await el.boxOk(pc);
      await pc.locator('.st', { hasText: '已取消' }).waitFor();
    });
    await flow.step('手机端：套餐里另一件商品的详情页也有这个套餐；“购买套餐”到结算页，套餐优惠一样', m, async () => {
      await uniLogin(m);
      await uniGo(m, `/pages/goods/detail?id=${b.goods_id}`);
      const box = m.locator('.bundles .bundle').first();
      await box.getByText(a.title).waitFor();
      await box.locator('.b-price', { hasText: price }).waitFor();
      await box.locator('.btn', { hasText: '购买套餐' }).click();
      await m.waitForURL(/pages\/checkout\/index\?sku_ids=/);
      await m.locator('.bundle-row', { hasText: `-¥${reduce.toFixed(2)}` }).waitFor();
      await m.locator('.item', { hasText: a.title }).waitFor();
    });
    await flow.step('后台：订单详情显示用了哪个套餐；停用套餐后 PC 商品页不再显示，再启用（留作示例）', admin, async () => {
      await admin.goto(config.admin + '/order/list');
      await admin.getByPlaceholder('订单号').fill(orderNo);
      await admin.getByPlaceholder('订单号').press('Enter');
      await el.row(admin, orderNo).getByRole('button', { name: '详情' }).click();
      await el.drawer(admin).getByText(`套餐优惠：-${reduce.toFixed(2)}`).waitFor();
      await el.drawer(admin).getByText(`${name} ×1`).waitFor();

      await admin.goto(config.admin + '/marketing/bundle');
      await el.row(admin, name).getByRole('button', { name: '停用' }).click();
      await el.message(admin, '已停用');
      await el.row(admin, name).getByText('已停用').waitFor();
      await pc.goto(`${config.pc}/#/goods/${a.goods_id}`);
      await pc.reload();
      await pc.locator('.gallery, .info, .panel').first().waitFor();
      await pc.waitForLoadState('networkidle').catch(() => {});
      if (await pc.locator('.bundles').count()) throw new Error('套餐停用后商品页还显示搭配套餐');
      await el.row(admin, name).getByRole('button', { name: '启用' }).click();
      await el.message(admin, '已启用');
      await el.row(admin, name).getByText('进行中').waitFor();
    });
  } finally {
    await adminCtx.close();
    await pc.context().close();
    await mctx.close();
  }

  return flow;
};
