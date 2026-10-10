// 浏览器点击测试入口。用法：node run.js [all|pc|uni|admin|dist|returns|security|wxpay|reviews|coupons|search|restock|messages|broadcast|invoice|bundle ...]
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

const FLOWS = { pc: require('./flows/pc'), uni: require('./flows/uni'), admin: require('./flows/admin'), dist: require('./flows/dist'), returns: require('./flows/returns'), security: require('./flows/security'), wxpay: require('./flows/wxpay'), reviews: require('./flows/reviews'), coupons: require('./flows/coupons'), search: require('./flows/search'), restock: require('./flows/restock'), messages: require('./flows/messages'), broadcast: require('./flows/broadcast'), invoice: require('./flows/invoice'), bundle: require('./flows/bundle') };
// 每个流程用到哪些前端
const NEEDS = { pc: ['pc'], uni: ['uni'], admin: ['admin'], dist: ['pc', 'admin', 'uni'], returns: ['pc', 'admin', 'uni'], security: ['pc', 'uni'], wxpay: ['uni'], reviews: ['pc', 'admin', 'uni'], coupons: ['pc', 'uni'], search: ['pc', 'uni'], restock: ['pc', 'admin', 'uni'], messages: ['pc', 'admin', 'uni'], broadcast: ['pc', 'admin', 'uni'], invoice: ['pc', 'admin', 'uni'], bundle: ['pc', 'admin', 'uni'] };
const NAMES = { pc: 'PC 端', uni: '手机端 H5', admin: '后台' };
// 自己造数据、不需要公共准备步骤（造订单、发管理员令牌）的流程
const SELF_CONTAINED = ['security', 'wxpay', 'reviews', 'coupons', 'search', 'restock', 'messages', 'broadcast', 'invoice', 'bundle'];

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

/**
 * 开发服务器给出的是不是磁盘上的最新代码。Windows 上 Vite 偶尔感知不到文件改动（切分支、合并时尤其容易），
 * 这时页面还是旧的，测试会在莫名其妙的地方失败。这里拿接口函数文件做探针：磁盘上导出的函数，服务器给出的文件里都得有。
 * 返回缺少的函数名；探测不了（比如指向的不是开发服务器）时返回空数组，不拦。
 */
async function staleExports(front) {
  const src = { pc: ['mall-pc', 'src', 'api', 'index.js'], uni: ['mall-uni', 'src', 'api', 'index.js'] }[front];
  if (!src) return [];
  try {
    const disk = fs.readFileSync(path.resolve(__dirname, '..', ...src), 'utf8');
    const res = await fetch(config[front] + '/src/api/index.js', { signal: AbortSignal.timeout(8000) });
    if (!res.ok) return [];
    const served = await res.text();
    return [...disk.matchAll(/^export (?:const|function|async function) (\w+)/gm)].map((m) => m[1]).filter((name) => !new RegExp('\\b' + name + '\\b').test(served));
  } catch (e) {
    return [];
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
  const stale = [];
  for (const f of fronts) {
    const missing = await staleExports(f);
    if (missing.length) stale.push(`${NAMES[f]}（${config[f]}）缺少：${missing.slice(0, 5).join('、')}`);
  }
  if (stale.length) {
    console.error('这些开发服务器给出的不是最新代码（Vite 没有感知到文件改动）：\n  ' + stale.join('\n  ') + '\n请重启对应的开发服务器后再跑。');
    // 不直接 process.exit：刚发过的探测请求还没收尾，Windows 上立刻退出会让 Node 报一个内部断言错误
    process.exitCode = 2;
    return;
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
