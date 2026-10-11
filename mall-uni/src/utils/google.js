/**
 * Google 登录按钮（手机端 H5 和 PC 端共用；小程序和 App 里没有）。
 * 用的是 Google 自己的脚本（Google Identity Services）：按钮由它画，点了之后弹出 Google 的窗口让买家选账号，
 * 选完把一个“身份凭证”交给 onCredential，页面再把凭证提交给商城后端验证。
 * 这个脚本只在后台启用了 Google 登录、并且买家打开登录页时才加载，别的页面不加载。
 */
const SRC = 'https://accounts.google.com/gsi/client'
let loading = null

function loadScript() {
  if (typeof window === 'undefined' || typeof document === 'undefined') return Promise.reject(new Error('no dom'))
  if (window.google && window.google.accounts && window.google.accounts.id) return Promise.resolve()
  if (!loading) {
    loading = new Promise((resolve, reject) => {
      const s = document.createElement('script')
      s.src = SRC
      s.async = true
      s.onload = () => (window.google && window.google.accounts && window.google.accounts.id ? resolve() : reject(new Error('gsi missing')))
      s.onerror = () => reject(new Error('gsi blocked'))
      document.head.appendChild(s)
    }).catch((e) => {
      // 失败了（网络不通、被拦截）下次进登录页再试
      loading = null
      throw e
    })
  }
  return loading
}

/**
 * 在 el 里画出 Google 登录按钮。加载不了脚本时什么都不画并返回 false（登录页照常用账号密码）。
 * @param {HTMLElement} el
 * @param {{clientId: string, lang: string, width: number, onCredential: (credential: string) => void}} options
 */
export async function renderGoogleButton(el, { clientId, lang, width, onCredential }) {
  if (!el || !clientId) return false
  try {
    await loadScript()
  } catch (e) {
    return false
  }
  const id = window.google.accounts.id
  id.initialize({ client_id: clientId, callback: (res) => res && res.credential && onCredential(res.credential), ux_mode: 'popup', auto_select: false })
  el.innerHTML = ''
  // 按钮上的文字由 Google 按 locale 给出；宽度只接受 200 到 400 之间的像素值
  id.renderButton(el, { type: 'standard', theme: 'outline', size: 'large', shape: 'pill', text: 'continue_with', logo_alignment: 'center', width: Math.min(400, Math.max(200, Math.round(width))), locale: lang })
  return true
}
