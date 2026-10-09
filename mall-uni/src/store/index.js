import { reactive } from 'vue'

// 全局状态（规模不大，直接用 reactive，不引入状态库）。token、语言、币种存本地，下次打开还在
const saved = (key, fallback = '') => {
  try { return uni.getStorageSync(key) || fallback } catch (e) { return fallback }
}

export const state = reactive({
  token: saved('gm_token'),
  member: null,
  lang: saved('gm_lang', 'zh-CN'),
  currency: saved('gm_currency', ''),
  // 启动配置（/config/init）：语言、币种、站点信息、短信和邮箱验证开关、微信登录开关
  config: { languages: [], currencies: [], site: {}, sms_enabled: false, email_verify_enabled: false, wechat_login_enabled: false, base_currency: '' },
  cartCount: 0,
  // 页面之间传递的一次性数据：结算页从地址列表选中的地址、首页跳分类时要选中的分类
  pickedAddressId: 0,
  pendingCategoryId: 0,
})

export function setToken(token, member = null) {
  state.token = token
  state.member = member
  uni.setStorageSync('gm_token', token)
}

/** 退出登录。remote=true 时由调用方先请求过退出接口 */
export function logout() {
  state.token = ''
  state.member = null
  state.cartCount = 0
  uni.removeStorageSync('gm_token')
}

export function setLang(lang) {
  state.lang = lang
  uni.setStorageSync('gm_lang', lang)
}

export function setCurrency(code) {
  state.currency = code
  uni.setStorageSync('gm_currency', code)
}

/** 当前币种的符号，如 ¥、$ */
export function currencySymbol(code = state.currency) {
  return state.config.currencies.find((c) => c.code === code)?.symbol || ''
}
