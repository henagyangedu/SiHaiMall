const fs = require('fs');
const path = require('path');
const config = require('./config');

/** 一组测试步骤的记录器：逐步执行、失败截图、收集页面里的脚本报错和 5xx 接口 */
class Flow {
  constructor(name) {
    this.name = name;
    this.results = [];
    this.problems = [];
    this.n = 0;
    fs.mkdirSync(config.shots, { recursive: true });
  }

  /** 监听一个页面：脚本报错、控制台错误、接口 5xx 都记下来，最后一起报告 */
  watch(page, who) {
    page.setDefaultTimeout(12000);
    page.on('pageerror', (e) => this.problems.push(`${who} 脚本报错：${e.message}`));
    page.on('console', (m) => { if (m.type() === 'error') this.problems.push(`${who} 控制台错误：${m.text().slice(0, 200)}`); });
    page.on('response', (r) => {
      if (r.status() >= 500 && /\/(admin-)?api\//.test(r.url())) this.problems.push(`${who} 接口 ${r.status()}：${r.url()}`);
    });
    return page;
  }

  /** 执行一步。抛出 Skip 表示这一步不适用（比如缺少对应的测试数据），不算失败 */
  async step(name, page, fn) {
    this.n++;
    try {
      await fn();
      this.results.push({ ok: true, name });
    } catch (e) {
      if (e instanceof Skip) {
        this.results.push({ ok: true, skipped: true, name, note: e.message });
        return;
      }
      const file = path.join(config.shots, `${this.name}_${this.n}.png`);
      await page.screenshot({ path: file }).catch(() => {});
      this.results.push({ ok: false, name, note: String(e.message).split('\n')[0].slice(0, 240), shot: file });
      // 关掉可能残留的弹窗，免得连累后面的步骤
      await page.keyboard.press('Escape').catch(() => {});
    }
  }

  get failed() {
    return this.results.filter((r) => !r.ok).length;
  }

  report() {
    console.log(`\n===== ${this.name} =====`);
    for (const r of this.results) {
      const mark = !r.ok ? '失败' : (r.skipped ? '跳过' : '通过');
      console.log(`${mark}  ${r.name}${r.note ? ' —— ' + r.note : ''}${r.shot ? '\n      截图：' + r.shot : ''}`);
    }
    const uniq = [...new Set(this.problems)];
    console.log(`共 ${this.results.length} 步，失败 ${this.failed} 步，跳过 ${this.results.filter((r) => r.skipped).length} 步`);
    if (uniq.length) console.log('页面问题：\n  ' + uniq.join('\n  '));
  }
}

class Skip extends Error {}

/** Element Plus 页面（后台、PC 端）里常用的几个定位 */
const el = {
  message: (page, text) => page.locator('.el-message', { hasText: text }).first().waitFor(),
  boxOk: (page) => page.locator('.el-message-box__btns .el-button--primary').click(),
  row: (page, text) => page.locator('.el-table__row', { hasText: text }).first(),
  dialog: (page) => page.locator('.el-dialog:visible').last(),
  drawer: (page) => page.locator('.el-drawer:visible').last(),
  option: (page, text) => page.locator('.el-select-dropdown:visible .el-select-dropdown__item', { hasText: text }).first(),
};

const desktop = { viewport: { width: 1400, height: 900 }, locale: 'zh-CN' };
const mobile = {
  viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'zh-CN',
  userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
};

/** 在 PC 端页面上用账号密码登录 */
async function pcLogin(page, account = config.account, password = config.password) {
  await page.goto(config.pc + '/#/login');
  await page.getByPlaceholder('邮箱或手机号').fill(account);
  await page.getByPlaceholder('密码').fill(password);
  await page.locator('.submit').click();
  await page.locator('.user', { hasText: '你好' }).waitFor();
}

/** 手机端 H5：整页重新打开目标页面（uni 的页面栈在只改 hash 时行为复杂，重开最稳） */
async function uniGo(page, p) {
  await page.goto('about:blank');
  await page.goto(config.uni + '/#' + p);
  await page.waitForLoadState('networkidle').catch(() => {});
}

/** 在手机端 H5 上用账号密码登录 */
async function uniLogin(page, account = config.account, password = config.password) {
  await uniGo(page, '/pages/login/index');
  await page.locator('uni-input input').nth(0).fill(account);
  await page.locator('uni-input input').nth(1).fill(password);
  await page.locator('.btn', { hasText: '登录' }).first().click();
  await page.locator('uni-toast').first().waitFor();
  await page.waitForTimeout(1500);
}

module.exports = { Flow, Skip, el, desktop, mobile, pcLogin, uniGo, uniLogin };
