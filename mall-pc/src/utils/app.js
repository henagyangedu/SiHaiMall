import { ElMessageBox } from 'element-plus'
import { state, setLang, setCurrency, logout, currencySymbol } from '@/store'
import { getInit, getProfile, getCart } from '@/api'
import { applyLang, t } from '@/locale'

/** 应用启动：取启动配置，校正语言和币种，恢复登录态 */
export async function bootstrap() {
  try {
    const cfg = await getInit()
    state.config = cfg
    const langs = cfg.languages.map((l) => l.code)
    if (!langs.includes(state.lang)) setLang(cfg.default_lang)
    const curs = cfg.currencies.map((c) => c.code)
    if (!curs.includes(state.currency)) setCurrency(cfg.base_currency)
  } catch (e) { /* 取不到配置时沿用本地保存的语言和币种 */ }
  applyLang(state.lang)

  if (state.token) {
    try {
      state.member = await getProfile()
      refreshCartCount()
    } catch (e) {
      logout()
    }
  }
}

/** 刷新页头购物车上的数量 */
export async function refreshCartCount() {
  if (!state.token) {
    state.cartCount = 0
    return
  }
  try {
    state.cartCount = (await getCart({ auth: false, silent: true })).count
  } catch (e) { /* 忽略 */ }
}

/** 金额显示：符号 + 金额，如 ¥99.90。currency 不传用当前币种 */
export function money(amount, currency) {
  return `${currencySymbol(currency)}${amount ?? '0.00'}`
}

/** 确认框：确定返回 true，取消返回 false */
export async function confirm(content) {
  try {
    await ElMessageBox.confirm(content, '', { confirmButtonText: t('common.confirm'), cancelButtonText: t('common.cancel'), type: 'warning' })
    return true
  } catch (e) {
    return false
  }
}

/** 收货地址拼成一行 */
export const addressLine = (a) => [a.province, a.city, a.district, a.address, a.address2].filter(Boolean).join(' ')
