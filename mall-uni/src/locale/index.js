import { createI18n } from 'vue-i18n'
import messages from './messages'
import { state } from '@/store'

// 界面文案：有哪些语言见 ./messages.js，没有文案的语言显示英文。
// 商品、文章等业务内容的多语言由后端按 X-Lang 返回，与这里无关。
export const i18n = createI18n({
  legacy: false,
  locale: state.lang,
  fallbackLocale: 'en',
  messages,
  missingWarn: false,
  fallbackWarn: false,
})

export const t = (key, params) => i18n.global.t(key, params || {})

/** 切换界面语言，同时更新底部导航的文字 */
export function applyLang(lang) {
  i18n.global.locale.value = lang
  refreshTabBar()
}

/**
 * 底部导航的文字是 pages.json 里写死的中文，要用接口改成当前语言。
 * 应用刚启动时底部导航可能还没准备好（启动配置回来得比它快），这时设置会失败，所以失败了隔一会儿再试几次。
 */
export function refreshTabBar(retry = 10) {
  let failed = false
  const keys = ['home', 'category', 'cart', 'user']
  keys.forEach((key, index) => {
    uni.setTabBarItem({
      index,
      text: t(`tab.${key}`),
      fail: () => {
        if (failed || retry <= 0) return
        failed = true
        setTimeout(() => refreshTabBar(retry - 1), 200)
      },
    })
  })
}
