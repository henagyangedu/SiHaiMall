const { execSync } = require('child_process');
const path = require('path');
const config = require('./config');

/**
 * 在后端执行 backend/ 下的一个辅助脚本（php artisan tinker <脚本>），返回它输出的那行 JSON。
 * 脚本用环境变量接收参数，用一行 "RESULT {json}" 返回结果；返回里带 error 字段时这里直接抛错。
 */
function backend(script, env = {}) {
  const file = path.resolve(__dirname, '..', 'backend', script + '.php');
  let out;
  try {
    out = execSync(`${config.php} artisan tinker "${file}"`, {
      cwd: config.home, env: { ...process.env, E2E_ACCOUNT: config.account, ...env }, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch (e) {
    throw new Error(`后端脚本 ${script} 执行失败：${String(e.stderr || e.stdout || e.message).slice(0, 400)}`);
  }
  const line = out.split(/\r?\n/).find((l) => l.startsWith('RESULT '));
  if (!line) throw new Error(`后端脚本 ${script} 没有返回结果：${out.slice(0, 400)}`);
  const data = JSON.parse(line.slice(7));
  if (data && data.error) throw new Error(`后端脚本 ${script}：${data.error}`);
  return data;
}

module.exports = { backend };
