import { reactive } from 'vue'

// 全局状态（规模不大，直接用 reactive，不引入状态库）。token、语言、币种存本地，下次打开还在
const saved = (key, fallback = '') => {
  try { return localStorage.getItem(key) || fallback } catch (e) { return fallback }
}
const save = (key, value) => {
  try { value ? localStorage.setItem(key, value) : localStorage.removeItem(key) } catch (e) { /* 隐私模式下存不了就算了 */ }
}

export const state = reactive({
  token: saved('gm_token'),
  member: null,
  lang: saved('gm_lang', 'zh-CN'),
  currency: saved('gm_currency', ''),
  // 启动配置（/config/init）：语言、币种、站点信息、短信和邮箱验证开关
  config: { languages: [], currencies: [], site: {}, sms_enabled: false, email_verify_enabled: false, base_currency: '' },
  cartCount: 0,
})

export function setToken(token, member = null) {
  state.token = token
  state.member = member
  save('gm_token', token)
}

export function logout() {
  state.token = ''
  state.member = null
  state.cartCount = 0
  save('gm_token', '')
}

export function setLang(lang) {
  state.lang = lang
  save('gm_lang', lang)
}

export function setCurrency(code) {
  state.currency = code
  save('gm_currency', code)
}

/** 当前币种的符号，如 ¥、$ */
export function currencySymbol(code = state.currency) {
  return state.config.currencies.find((c) => c.code === code)?.symbol || ''
}
