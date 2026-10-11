const fs = require('fs');
const path = require('path');

// 后端的地址：后台开发服务器把接口转发到哪里，就是哪里（admin-web 的 .env.development.local 优先，其次 .env.development）
function backendUrl() {
  for (const name of ['.env.development.local', '.env.development']) {
    try {
      const m = fs.readFileSync(path.resolve(__dirname, '..', '..', 'admin-web', name), 'utf8').match(/^\s*VITE_API_PROXY\s*=\s*(\S+)/m);
      if (m) return m[1].replace(/\/+$/, '');
    } catch (e) { /* 没有这个文件，看下一个 */ }
  }
  return 'http://127.0.0.1:8000';
}

// 运行参数，都可以用环境变量覆盖。默认值对应本机的开发环境（见根目录 CLAUDE.md 的“本地访问方式”）
module.exports = {
  // 三个前端开发服务器的地址。测的是这些服务器当前提供的页面，跑之前要先把它们启动起来
  admin: process.env.E2E_ADMIN_URL || 'http://127.0.0.1:5173',
  pc: process.env.E2E_PC_URL || 'http://127.0.0.1:5190',
  uni: process.env.E2E_UNI_URL || 'http://127.0.0.1:5180',

  // 后端的地址。只有直接调后端的流程用得到（开放接口），页面上的操作都走上面三个开发服务器的转发
  api: process.env.E2E_API_URL || backendUrl(),

  // 用来登录 C 端的测试会员（开发库里的冒烟账号）。只能用于开发 / 测试环境
  account: process.env.E2E_ACCOUNT || 'smoke@example.com',
  password: process.env.E2E_PASSWORD || 'Smoke#12345',

  // 后端目录和 PHP 命令：准备测试数据、清理都通过 php artisan tinker 执行 backend/ 下的脚本
  home: process.env.E2E_HOME || path.resolve(__dirname, '..', '..', 'home'),
  php: process.env.E2E_PHP || 'php',

  // 浏览器：默认用系统里已安装的 Edge，不用另外下载浏览器；可以改成 chrome
  browserChannel: process.env.E2E_BROWSER || 'msedge',
  // 设为 1 时显示浏览器窗口，方便看它在点什么
  headed: process.env.E2E_HEADED === '1',

  shots: path.resolve(__dirname, '..', 'shots'),
};
