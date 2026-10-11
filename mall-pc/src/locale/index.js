import { createI18n } from 'vue-i18n'
import { computed } from 'vue'
import messages from '@shared/locale/messages'
import elZhCn from 'element-plus/es/locale/lang/zh-cn'
import elEn from 'element-plus/es/locale/lang/en'
import elEs from 'element-plus/es/locale/lang/es'
import elFr from 'element-plus/es/locale/lang/fr'
import elDe from 'element-plus/es/locale/lang/de'
import elJa from 'element-plus/es/locale/lang/ja'
import { state } from '@/store'
import pc from './pc'

// 界面文案：通用部分与手机端共用 mall-uni/src/locale 下的同一份，PC 端独有的在 ./pc.js（挂在 pc.* 下）。
// 有哪些语言见 mall-uni/src/locale/messages.js，没有文案的语言显示英文。
// 商品、文章等业务内容的多语言由后端按 X-Lang 返回，与这里无关。
export const i18n = createI18n({
  legacy: false,
  locale: state.lang,
  fallbackLocale: 'en',
  messages: Object.fromEntries(Object.entries(messages).map(([code, m]) => [code, { ...m, pc: pc[code] || pc[code === 'zh' ? 'zh-CN' : 'en'] }])),
  missingWarn: false,
  fallbackWarn: false,
})

export const t = (key, params) => i18n.global.t(key, params || {})

// 组件库自带的文字（图片预览、空状态、分页等）跟着界面语言走，找的顺序和界面文案一样：完整编码 → 前半段 → 英文
const EL = { zh: elZhCn, en: elEn, es: elEs, fr: elFr, de: elDe, ja: elJa }
export const elLocale = computed(() => EL[String(state.lang).split('-')[0].toLowerCase()] || elEn)

export function applyLang(lang) {
  i18n.global.locale.value = lang
  document.documentElement.lang = lang
}
