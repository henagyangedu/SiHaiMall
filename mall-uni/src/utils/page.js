import { watch } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { state } from '@/store'
import { t } from '@/locale'

/** 页面标题跟随界面语言：进入页面和切换语言时都更新导航栏标题 */
export function usePageTitle(key) {
  const apply = () => uni.setNavigationBarTitle({ title: typeof key === 'function' ? key() : t(key) })
  onShow(apply)
  watch(() => state.lang, apply)
}

/** 带确认的操作 */
export function confirm(content) {
  return new Promise((resolve) => {
    uni.showModal({ content, confirmText: t('common.confirm'), cancelText: t('common.cancel'), success: (r) => resolve(r.confirm) })
  })
}

export const toast = (title) => uni.showToast({ title, icon: 'none', duration: 2000 })

/** 后台有没有配客服联系方式（在线客服链接或电话）；都没配时不显示“联系客服”入口 */
export const hasService = () => !!(state.config.site?.service_url || state.config.site?.service_phone)

/**
 * 联系客服：配了在线客服链接就打开它，否则拨客服电话。
 * 小程序里不能直接打开外部网页，所以改为把链接复制到剪贴板并提示；有电话时让买家选。
 */
export function contactService() {
  const site = state.config.site || {}
  const call = () => uni.makePhoneCall({ phoneNumber: String(site.service_phone), fail: () => {} })
  if (!site.service_url) {
    if (site.service_phone) call()
    return
  }
  // #ifdef H5
  window.open(site.service_url, '_blank', 'noopener')
  // #endif
  // #ifndef H5
  const copy = () => uni.setClipboardData({ data: site.service_url, showToast: false, success: () => toast(t('service.linkCopied')) })
  if (!site.service_phone) return copy()
  uni.showActionSheet({ itemList: [t('service.online'), `${t('service.call')} ${site.service_phone}`], success: ({ tapIndex }) => (tapIndex === 0 ? copy() : call()) })
  // #endif
}

/** 模板里不能直接访问全局的 uni，页面跳转用这两个函数 */
export const nav = (url) => uni.navigateTo({ url })
export const tab = (url) => uni.switchTab({ url })
