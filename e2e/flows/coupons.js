const config = require('../lib/config');
const { backend } = require('../lib/backend');
const { Flow, el, desktop, mobile, pcLogin, uniGo, uniLogin } = require('../lib/harness');

/**
 * 优惠券，买家这一侧（PC 端和手机端）：
 * 领券中心领一张 → 商品页能看到这张券 → 结算时自动用上、可以选择不用 → 下单后订单上有优惠券金额 → 取消订单后券回到“未使用”
 * → 手机端的优惠券页和结算页显示同一张券。
 * 券是测试自己建的（无门槛减 5），结束时由 cleanup.php 删掉。
 */
module.exports = async function couponsFlow(browser) {
  const flow = new Flow('coupons');
  const made = backend('coupons');
  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const card = () => pc.locator('.coupon', { hasText: made.coupon_name });
  const amount = async (sel) => Number((await pc.locator(sel).textContent()).replace(/[^\d.]/g, ''));
  // 结算页的优惠券下拉里选一项
  const pickCoupon = async (text) => {
    await pc.locator('.coupon-pick').click();
    await el.option(pc, text).click();
    await pc.locator('.el-select-dropdown:visible').first().waitFor({ state: 'hidden' });
  };
  let orderNo = '';

  try {
    await flow.step('PC：会员中心进“优惠券”，在可领取里领一张，按钮变成已领取，出现在未使用里', pc, async () => {
      await pcLogin(pc);
      await pc.goto(config.pc + '/#/user');
      await pc.locator('.menu .item', { hasText: '优惠券' }).click();
      await pc.waitForURL(/#\/coupons$/);
      await card().waitFor();
      await card().getByText('减 ¥5').waitFor();
      await card().getByText('无门槛').waitFor();
      await card().getByRole('button', { name: '领取' }).click();
      await el.message(pc, '领取成功');
      await card().getByRole('button', { name: '已领取' }).waitFor();
      if (!(await card().getByRole('button', { name: '已领取' }).isDisabled())) throw new Error('领过之后按钮应该不能再点');
      await pc.getByRole('tab', { name: '未使用' }).click();
      await card().waitFor();
      await card().getByText('有效期至').waitFor();
    });
    await flow.step('PC：商品详情页显示这个商品能领的券', pc, async () => {
      await pc.goto(`${config.pc}/#/goods/${made.goods_id}`);
      await pc.locator('.coupons .cp-tag', { hasText: '减 ¥5' }).first().waitFor();
    });
    await flow.step('PC：立即购买，结算页自动用上这张券，可以改成不用再改回来', pc, async () => {
      await pc.getByRole('button', { name: '立即购买' }).click();
      await pc.waitForURL(/#\/checkout/);
      await pc.locator('.coupon-row .price', { hasText: '5.00' }).waitFor();
      const withCoupon = await amount('.total');
      await pickCoupon('不使用优惠券');
      await pc.locator('.coupon-row .price').waitFor({ state: 'detached' });
      const without = await amount('.total');
      if (Math.abs(without - withCoupon - 5) > 0.001) throw new Error(`不用券应该贵 5 元：用券 ${withCoupon}，不用 ${without}`);
      await pickCoupon(made.coupon_name);
      await pc.locator('.coupon-row .price', { hasText: '5.00' }).waitFor();
      if (Math.abs((await amount('.total')) - withCoupon) > 0.001) throw new Error('改回用券后应付金额不对');
    });
    await flow.step('PC：提交订单，订单详情里有优惠券金额，这张券变成已使用', pc, async () => {
      await pc.getByRole('button', { name: '提交订单' }).click();
      await pc.waitForURL(/#\/orders\/GM\w+/);
      orderNo = pc.url().match(/orders\/(GM\w+)/)[1];
      const dlg = pc.locator('.el-dialog:visible', { hasText: '选择支付方式' });
      await dlg.waitFor();
      await dlg.getByRole('button', { name: '取消' }).click();
      await pc.locator('.kv', { hasText: '优惠券' }).getByText('5.00').waitFor();
      await pc.goto(config.pc + '/#/coupons');
      await pc.reload();
      await pc.getByRole('tab', { name: '已使用' }).click();
      await card().waitFor();
    });
    await flow.step('PC：取消订单后，这张券回到未使用', pc, async () => {
      await pc.goto(`${config.pc}/#/orders/${orderNo}`);
      await pc.getByRole('button', { name: '取消订单' }).click();
      await el.boxOk(pc);
      await pc.locator('.st', { hasText: '已取消' }).waitFor();
      await pc.goto(config.pc + '/#/coupons');
      await pc.reload();
      await pc.getByRole('tab', { name: '未使用' }).click();
      await card().waitFor();
      await pc.getByRole('tab', { name: '已使用' }).click();
      await pc.waitForLoadState('networkidle').catch(() => {});
      if (await card().count()) throw new Error('退回的券还显示在已使用里');
    });
    await flow.step('手机端：优惠券页的未使用里有这张券，结算页自动用上', pc, async () => {
      const mctx = await browser.newContext(mobile);
      const m = flow.watch(await mctx.newPage(), '手机端');
      try {
        await uniLogin(m);
        await uniGo(m, '/pages/user/index');
        await m.locator('.cell', { hasText: '优惠券' }).first().click();
        // 可领取里它是“已领取”
        await m.locator('.coupon', { hasText: made.coupon_name }).locator('.act.off', { hasText: '已领取' }).waitFor();
        await m.locator('.tab', { hasText: '未使用' }).click();
        await m.locator('.coupon', { hasText: made.coupon_name }).getByText('减 ¥5').waitFor();
        await uniGo(m, `/pages/goods/detail?id=${made.goods_id}`);
        await m.locator('.coupons .cp-tag', { hasText: '减 ¥5' }).first().waitFor();
        await uniGo(m, `/pages/checkout/index?sku_id=${made.sku_id}&qty=1`);
        await m.locator('.kv', { hasText: '优惠券' }).locator('.price', { hasText: '5.00' }).waitFor();
      } finally {
        await mctx.close();
      }
    });
  } finally {
    await pc.context().close();
  }

  return flow;
};
