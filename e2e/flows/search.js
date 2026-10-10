const config = require('../lib/config');
const { Flow, Skip, desktop, mobile, uniGo } = require('../lib/harness');

/**
 * 商品搜索（PC 端和手机端，不用登录）：
 * 点开搜索框看到热门搜索词 → 点一个词搜到商品 → 这个词进了搜索历史 → 搜一个不存在的词，看到“没有找到”和推荐商品 → 清空历史。
 * 热门搜索词用的是后台“设置 → 基础信息”里配的；没配的话，和热门词有关的步骤跳过，改用商品名里的一个词去搜。
 */
module.exports = async function searchFlow(browser) {
  const flow = new Flow('search');
  const NONE = '不存在的商品词zzz';
  const site = (await (await fetch(config.pc + '/api/config/init')).json()).data.site;
  const hot = site.hot_words || [];
  // 没配热门词时用来搜索的词：第一件商品名称的前两个字
  const first = (await (await fetch(config.pc + '/api/goods?size=1')).json()).data.list[0];
  const word = hot[0] || first.title.slice(0, 2);

  const pc = flow.watch(await (await browser.newContext(desktop)).newPage(), 'PC');
  const input = pc.locator('.search input');
  const mctx = await browser.newContext(mobile);
  const m = flow.watch(await mctx.newPage(), '手机端');
  const mInput = m.locator('.search input');

  try {
    await flow.step('PC：点开搜索框，下面展开热门搜索词；点一个词，搜到商品', pc, async () => {
      if (!hot.length) throw new Skip('后台没有配热门搜索词');
      await pc.goto(config.pc + '/#/');
      await input.click();
      await pc.locator('.search-drop .word.hot', { hasText: hot[0] }).first().click();
      await pc.waitForURL(/#\/goods\?keyword=/);
      await pc.locator('.grid .card').first().waitFor();
      if ((await input.inputValue()) !== hot[0]) throw new Error('搜索框里应该显示刚才点的词');
    });
    await flow.step('PC：输入词回车搜索，再点开搜索框，这个词在搜索历史里；点别处收起', pc, async () => {
      await pc.goto(config.pc + '/#/');
      await input.fill(word);
      await input.press('Enter');
      await pc.waitForURL(/#\/goods\?keyword=/);
      await pc.locator('.grid .card').first().waitFor();
      await input.click();
      await pc.locator('.search-drop .word:not(.hot)', { hasText: word }).first().waitFor();
      await pc.locator('.bar .sorts').click();
      await pc.locator('.search-drop').waitFor({ state: 'detached' });
    });
    await flow.step('PC：搜不存在的词，提示没有找到，下面有推荐商品', pc, async () => {
      await input.fill(NONE);
      await input.press('Enter');
      await pc.locator('.no-result', { hasText: NONE }).waitFor();
      await pc.locator('.grid.fallback .card').first().waitFor();
    });
    await flow.step('PC：两个词用空格分开，都要命中；搜索历史里最近的排最前，可以清空', pc, async () => {
      // 一个能搜到的词 + 一个搜不到的词 = 没有结果
      await input.fill(`${word} ${NONE}`);
      await input.press('Enter');
      await pc.locator('.no-result').waitFor();
      await input.click();
      const words = await pc.locator('.search-drop .word:not(.hot)').allTextContents();
      if (words[0] !== `${word} ${NONE}` || !words.includes(NONE) || !words.includes(word)) throw new Error('搜索历史不对：' + JSON.stringify(words));
      await pc.locator('.search-drop .words-clear').click();
      await pc.locator('.search-drop .word:not(.hot)').first().waitFor({ state: 'detached' });
      await pc.reload();
      await input.click();
      if (await pc.locator('.search-drop .word:not(.hot)').count()) throw new Error('清空后刷新页面，搜索历史又出现了');
    });

    await flow.step('手机端：点搜索框出现热门搜索，首页内容收起；点一个词搜到商品', m, async () => {
      if (!hot.length) throw new Skip('后台没有配热门搜索词');
      await uniGo(m, '/pages/index/index');
      await mInput.click();
      await m.locator('.search-panel .word.hot', { hasText: hot[0] }).first().waitFor();
      if (await m.locator('.grid .item').count()) throw new Error('看搜索历史和热门词时不应该显示商品');
      await m.locator('.search-panel .word.hot', { hasText: hot[0] }).first().click();
      await m.locator('.section', { hasText: `“${hot[0]}”的搜索结果` }).waitFor();
      await m.locator('.grid .item').first().waitFor();
    });
    await flow.step('手机端：搜不存在的词，提示没有找到并给出推荐商品；点取消回到首页', m, async () => {
      await uniGo(m, '/pages/index/index');
      await mInput.click();
      await mInput.fill(NONE);
      await mInput.press('Enter');
      await m.locator('.no-result', { hasText: NONE }).waitFor();
      await m.locator('.grid .item').first().waitFor();
      await m.locator('.search-cancel').click();
      await m.locator('.no-result').waitFor({ state: 'detached' });
      await m.locator('.section', { hasText: '为你推荐' }).waitFor();
      await m.locator('.grid .item').first().waitFor();
      // uni 的输入框把值同步到页面上是异步的，等它清空
      await m.waitForFunction(() => document.querySelector('.search input').value === '').catch(() => { throw new Error('取消后搜索框应该清空'); });
    });
    await flow.step('手机端：搜过的词在搜索历史里，点它直接搜；可以清空', m, async () => {
      await mInput.click();
      await mInput.fill(word);
      await mInput.press('Enter');
      await m.locator('.grid .item').first().waitFor();
      await m.locator('.search-cancel').click();
      await mInput.click();
      const words = await m.locator('.search-panel .word:not(.hot)').allTextContents();
      if (words[0] !== word || !words.includes(NONE)) throw new Error('搜索历史不对：' + JSON.stringify(words));
      await m.locator('.search-panel .word:not(.hot)', { hasText: word }).first().click();
      await m.locator('.section', { hasText: `“${word}”的搜索结果` }).waitFor();
      await m.locator('.search-cancel').click();
      await mInput.click();
      await m.locator('.search-panel .words-clear').click();
      await m.locator('.search-panel .word:not(.hot)').first().waitFor({ state: 'detached' });
    });
  } finally {
    await pc.context().close();
    await mctx.close();
  }

  return flow;
};
