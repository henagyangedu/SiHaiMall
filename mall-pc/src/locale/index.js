import { createI18n } from 'vue-i18n'
import zhCN from '@shared/locale/zh-CN'
import en from '@shared/locale/en'
import { state } from '@/store'
import pc from './pc'

// 界面文案：通用部分与手机端共用 mall-uni/src/locale 下的同一份，PC 端独有的在 ./pc.js（挂在 pc.* 下）。
// 中文语言用中文，后台新增的其他语言在补充文案之前先显示英文。
// 商品、文章等业务内容的多语言由后端按 X-Lang 返回，与这里无关。
export const i18n = createI18n({
  legacy: false,
  locale: state.lang,
  fallbackLocale: 'en',
  messages: { 'zh-CN': { ...zhCN, pc: pc['zh-CN'] }, en: { ...en, pc: pc.en } },
  missingWarn: false,
  fallbackWarn: false,
})

export const t = (key, params) => i18n.global.t(key, params || {})

export function applyLang(lang) {
  i18n.global.locale.value = lang
  document.documentElement.lang = lang
}
