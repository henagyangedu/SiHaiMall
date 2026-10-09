import { createI18n } from 'vue-i18n'
import zhCN from './zh-CN'
import en from './en'
import { state } from '@/store'

// 界面文案：中文语言用中文，后台新增的其他语言在补充文案之前先显示英文。
// 商品、文章等业务内容的多语言由后端按 X-Lang 返回，与这里无关。
export const i18n = createI18n({
  legacy: false,
  locale: state.lang,
  fallbackLocale: 'en',
  messages: { 'zh-CN': zhCN, en },
  missingWarn: false,
  fallbackWarn: false,
})

export const t = (key, params) => i18n.global.t(key, params || {})

/** 切换界面语言，同时更新底部导航的文字 */
export function applyLang(lang) {
  i18n.global.locale.value = lang
  refreshTabBar()
}

export function refreshTabBar() {
  ['home', 'category', 'cart', 'user'].forEach((key, index) => {
    uni.setTabBarItem({ index, text: t(`tab.${key}`), fail: () => {} })
  })
}
