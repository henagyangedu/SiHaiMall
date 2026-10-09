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

/** 模板里不能直接访问全局的 uni，页面跳转用这两个函数 */
export const nav = (url) => uni.navigateTo({ url })
export const tab = (url) => uni.switchTab({ url })
