// 检查界面文案：每种语言的键要和英文的一模一样，文案里的变量（{n} 这种）也要对得上。
// 用法：在 mall-uni 下 npm run check:locale。手机端的 src/locale/*.js 和 PC 端的 mall-pc/src/locale/pc.js 都查。
import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const dir = join(root, 'src/locale')

// 文案文件都是“export default { … }”，不依赖别的模块，直接当成表达式求值
const load = (file) => new Function(readFileSync(file, 'utf8').replace('export default', 'return'))()

function flatten(obj, prefix = '', out = {}) {
  for (const [k, v] of Object.entries(obj)) {
    if (v && typeof v === 'object') flatten(v, `${prefix}${k}.`, out)
    else out[prefix + k] = String(v)
  }
  return out
}
const vars = (text) => [...new Set(text.match(/\{[a-zA-Z_]+\}/g) || [])].sort().join(',')

const problems = []
function compare(scope, all) {
  const en = flatten(all.en)
  const zh = flatten(all['zh-CN'])
  for (const [code, messages] of Object.entries(all)) {
    if (code === 'en') continue
    const flat = flatten(messages)
    for (const key of Object.keys(en)) if (!(key in flat)) problems.push(`${scope} ${code}：缺少 ${key}`)
    for (const key of Object.keys(flat)) {
      if (!(key in en)) problems.push(`${scope} ${code}：多了英文里没有的 ${key}`)
      else if (flat[key].trim() === '') problems.push(`${scope} ${code}：${key} 是空的`)
      // 变量和英文或中文的任一个对上就行（个别文案中英文用的变量不同，比如折扣券的“9 折”和“10% off”）
      else if (vars(flat[key]) !== vars(en[key]) && vars(flat[key]) !== vars(zh[key] ?? '')) problems.push(`${scope} ${code}：${key} 的变量是 ${vars(flat[key]) || '（无）'}，英文是 ${vars(en[key]) || '（无）'}`)
    }
  }
}

const shared = {}
for (const file of readdirSync(dir)) {
  if (!file.endsWith('.js') || file === 'index.js' || file === 'messages.js') continue
  shared[file.slice(0, -3)] = load(join(dir, file))
}
compare('通用文案', shared)

const pc = load(join(root, '../mall-pc/src/locale/pc.js'))
compare('PC 端文案', pc)

// 文案文件、messages.js 里的登记、PC 端文案，三处的语言要一致
const registry = readFileSync(join(dir, 'messages.js'), 'utf8')
for (const code of Object.keys(shared)) {
  if (!new RegExp(`from './${code}'`).test(registry)) problems.push(`${code}.js 没有在 messages.js 里登记`)
  if (!pc[code]) problems.push(`mall-pc/src/locale/pc.js 里没有 ${code}`)
}
for (const code of Object.keys(pc)) if (!shared[code]) problems.push(`PC 端文案里的 ${code} 没有对应的通用文案文件`)

if (problems.length) {
  console.error(problems.join('\n'))
  console.error(`\n共 ${problems.length} 处问题`)
  process.exit(1)
}
console.log(`界面文案检查通过：${Object.keys(shared).join('、')}，通用文案 ${Object.keys(flatten(shared.en)).length} 条，PC 端 ${Object.keys(flatten(pc.en)).length} 条`)
