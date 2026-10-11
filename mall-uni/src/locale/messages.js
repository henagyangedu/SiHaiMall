import zhCN from './zh-CN'
import en from './en'
import es from './es'
import fr from './fr'
import de from './de'
import ja from './ja'

/**
 * 界面文案（手机端和 PC 端共用）。键是后台“设置 → 语言管理”里的语言编码。
 * - 带地区的编码按前半段找：es-MX 用 es，pt-BR 没有 pt 就用英文（vue-i18n 自己会这样逐级回退）。
 * - zh 是给 zh-TW、zh-HK 这类编码兜底的：还没有繁体文案，先显示简体，比显示英文合适。
 * - 都找不到的语言显示英文。
 * 新增一种语言：照 en.js 写一份、在这里登记、PC 端独有的文案加到 mall-pc/src/locale/pc.js，
 * 然后在 mall-uni 下执行 npm run check:locale 检查有没有漏的键和变量。
 */
export default { 'zh-CN': zhCN, zh: zhCN, en, es, fr, de, ja }
