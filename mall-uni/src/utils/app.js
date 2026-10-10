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

/** 刷新底部导航上的购物车数量角标 */
export async function refreshCartCount() {
  if (!state.token) {
    state.cartCount = 0
  } else {
    try {
      state.cartCount = (await getCart({ auth: false, silent: true })).count
    } catch (e) { /* 忽略 */ }
  }
  if (state.cartCount > 0) uni.setTabBarBadge({ index: 2, text: String(Math.min(state.cartCount, 99)), fail: () => {} })
  else uni.removeTabBarBadge({ index: 2, fail: () => {} })
}

/** 金额显示：符号 + 金额，如 ¥99.90。$currency 不传用当前币种 */
export function money(amount, currency) {
  return `${currencySymbol(currency)}${amount ?? '0.00'}`
}

/**
 * 把一张优惠券写成买家看的文字（按当前语言）。券面金额是基准币种的。
 * valueOnly：只要面额（“减 ¥30”“9 折”）；condOnly：只要使用条件（“满 ¥300 可用，最多减 ¥50”）；都不传是完整的一句。
 * c 可以是领券中心的券，也可以是会员手里的券（那种的面额在 face_amount）。
 */
export function couponText(c, valueOnly = false, condOnly = false) {
  const sym = currencySymbol(state.config.base_currency)
  const n = (v) => sym + Number(v)
  const value = c.type === 1
    ? t('coupon.reduce', { n: n(c.face_amount ?? c.amount) })
    : t('coupon.zhe', { zhe: Number(c.discount) / 10, pct: 100 - Number(c.discount) })
  const cond = [
    Number(c.threshold) > 0 ? t('coupon.over', { n: n(c.threshold) }) : t('coupon.noThreshold'),
    c.type === 2 && Number(c.max_amount) > 0 ? t('coupon.max', { n: n(c.max_amount) }) : '',
  ].filter(Boolean).join(state.lang.startsWith('zh') ? '，' : ', ')
  if (valueOnly) return value
  if (condOnly) return cond
  return `${value} · ${cond}`
}

/** 一张券的有效期说明：领券中心里“领取后 N 天”的券显示天数，其余显示截止时间 */
export function couponValidText(c) {
  if (c.valid_type === 2 && !c.id) return t('coupon.validDays', { n: c.valid_days })
  return t('coupon.validTo', { time: String(c.valid_end || '').slice(0, 16) })
}
