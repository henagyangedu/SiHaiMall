import { state } from '@/store'
import { t } from '@/locale'

/**
 * 本地存储告知条（只在 H5 里有，小程序里不显示）：后台“基础信息”里开启后，首次访问在页面底部显示一次，点“知道了”后不再出现。
 * 本站不用 Cookie、不接统计和广告脚本，只存登录状态、语言、币种这些必要的东西，所以只是告知，不需要“接受 / 拒绝”。
 * uni-app 没有全站共用的根模板，所以直接往 body 里加一个节点；文字是显示那一刻的语言。
 */
const KEY = 'gm_storage_notice'

export function showStorageNotice() {
  // #ifdef H5
  if (Number(state.config.site?.storage_notice_enabled) !== 1 || uni.getStorageSync(KEY) || document.getElementById('gm-storage-notice')) return
  const box = document.createElement('div')
  box.id = 'gm-storage-notice'
  // 压在底部导航上面
  box.style.cssText = 'position:fixed;left:12px;right:12px;bottom:calc(62px + env(safe-area-inset-bottom));z-index:9999;background:#fff;border-radius:12px;'
    + 'box-shadow:0 4px 20px rgba(41,43,39,.18);padding:12px 14px;font-family:system-ui,-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;font-size:13px;line-height:1.6;color:#292b27;display:flex;align-items:center;gap:12px;'
  const text = document.createElement('div')
  text.style.flex = '1'
  text.textContent = t('privacy.noticeText') + ' '
  const link = document.createElement('a')
  link.textContent = t('privacy.noticeLink')
  link.style.cssText = 'color:#c56948;text-decoration:none;'
  link.onclick = () => uni.navigateTo({ url: '/pages/protocol/index?key=privacy_policy' })
  text.appendChild(link)
  const ok = document.createElement('div')
  ok.textContent = t('privacy.noticeOk')
  ok.style.cssText = 'background:#c56948;color:#fff;border-radius:16px;padding:5px 14px;white-space:nowrap;'
  ok.onclick = () => {
    uni.setStorageSync(KEY, '1')
    box.remove()
  }
  box.appendChild(text)
  box.appendChild(ok)
  document.body.appendChild(box)
  // #endif
}
