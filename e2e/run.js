// 浏览器点击测试入口。用法：node run.js [all|pc|uni|admin|dist|returns|security ...]
//
// 用本机已安装的 Edge 真实操作三个前端（后台、PC 端、手机端 H5），检查主流程能不能走通。
// 运行前要求：后端可用；要测的前端开发服务器已经启动（地址见 lib/config.js，可用环境变量覆盖）；
// 开发库里有测试会员（默认 smoke@example.com）、至少一个收货地址和一个单规格的上架商品。
// 会往开发库里写测试数据（订单、售后单、下级账号等），生产环境会被拒绝。
const fs = require('fs');
const os = require('os');
const path = require('path');
const { chromium } = require('playwright-core');
const config = require('./lib/config');
const { backend } = require('./lib/backend');

const FLOWS = { pc: require('./flows/pc'), uni: require('./flows/uni'), admin: require('./flows/admin'), dist: require('./flows/dist'), returns: require('./flows/returns'), security: require('./flows/security') };
// 每个流程用到哪些前端
const NEEDS = { pc: ['pc'], uni: ['uni'], admin: ['admin'], dist: ['pc', 'admin', 'uni'], returns: ['pc', 'admin', 'uni'], security: ['pc', 'uni'] };
const NAMES = { pc: 'PC 端', uni: '手机端 H5', admin: '后台' };
// 自己造数据、不需要公共准备步骤（造订单、发管理员令牌）的流程
const SELF_CONTAINED = ['security'];

/** 一张 1×1 的 PNG：上传凭证、头像用 */
function testImage() {
  const file = path.join(os.tmpdir(), 'gm-e2e-proof.png');
  fs.writeFileSync(file, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64'));
  return file;
}

async function reachable(url) {
  try {
    const res = await fetch(url, { signal: AbortSignal.timeout(5000) });
    return res.ok;
  } catch (e) {
    return false;
  }
}

(async () => {
  const args = process.argv.slice(2);
  const wanted = !args.length || args.includes('all') ? Object.keys(FLOWS) : args;
  const unknown = wanted.filter((w) => !FLOWS[w]);
  if (unknown.length) {
    console.error(`不认识的流程：${unknown.join('、')}。可选：all、${Object.keys(FLOWS).join('、')}`);
    process.exit(2);
  }

  // 先确认要用到的开发服务器都开着，免得跑到一半才发现
  const fronts = [...new Set(wanted.flatMap((w) => NEEDS[w]))];
  const down = [];
  for (const f of fronts) if (!(await reachable(config[f]))) down.push(`${NAMES[f]}（${config[f]}）`);
  if (down.length) {
    console.error('这些开发服务器没有启动或连不上：\n  ' + down.join('\n  ') + '\n请先启动它们，或用 E2E_ADMIN_URL / E2E_PC_URL / E2E_UNI_URL 指定地址。');
    process.exit(2);
  }

  let failed = 0;
  let browser = null;
  try {
    for (const name of wanted) {
      // 每个流程各自准备一份测试数据（售后、退款会把订单用掉）
      const data = SELF_CONTAINED.includes(name) ? {} : { ...backend('prepare'), image: testImage() };
      browser = browser || await chromium.launch({ channel: config.browserChannel, headless: !config.headed });
      const flow = await FLOWS[name](browser, data);
      flow.report();
      failed += flow.failed;
    }
  } catch (e) {
    console.error('\n运行中断：' + e.message);
    failed++;
  } finally {
    if (browser) await browser.close().catch(() => {});
    try {
      backend('cleanup');
    } catch (e) {
      console.error('清理失败：' + e.message);
    }
  }

  console.log(failed ? `\n有 ${failed} 步失败。失败步骤的截图在 ${config.shots}` : '\n全部通过');
  process.exit(failed ? 1 : 0);
})();
