import { state, logout } from '@/store'
import { t } from '@/locale'

// 来源端：后端按 X-Source 区分 h5 / mp / app / pc
let source = 'h5'
// #ifdef MP
source = 'mp'
// #endif
// #ifdef APP-PLUS
source = 'app'
// #endif

const BASE = import.meta.env.VITE_API_BASE || '/api'

/**
 * 统一请求。后端返回 {code, msg, data}：code=0 成功，401 未登录，其它为业务错误。
 * 成功时 resolve data；失败默认弹出提示并 reject（传 silent: true 则不弹）。
 * 未登录默认跳到登录页（传 auth: false 表示这个请求未登录也没关系，不跳转）。
 */
export function request(method, url, data = {}, { silent = false, auth = true } = {}) {
  return new Promise((resolve, reject) => {
    const header = { 'X-Lang': state.lang, 'X-Currency': state.currency, 'X-Source': source }
    if (state.token) header.Authorization = `Bearer ${state.token}`

    uni.request({
      url: BASE + url,
      method,
      data,
      header,
      success: (res) => {
        const body = res.data
        if (body && body.code === 0) return resolve(body.data)
        if (body && body.code === 401) {
          logout(false)
          if (auth) toLogin()
          return reject(body)
        }
        const msg = (body && body.msg) || t('common.requestFailed')
        if (!silent) uni.showToast({ title: msg, icon: 'none', duration: 2500 })
        reject(body || { code: -1, msg })
      },
      fail: () => {
        if (!silent) uni.showToast({ title: t('common.networkError'), icon: 'none' })
        reject({ code: -1, msg: t('common.networkError') })
      },
    })
  })
}

/**
 * 上传一张本地图片（uni.chooseImage 得到的临时路径），成功时 resolve {id, url, ...}。
 * scene：refund 售后凭证 / avatar 头像。失败时弹出提示并 reject。
 */
export function upload(filePath, scene) {
  return new Promise((resolve, reject) => {
    const header = { 'X-Lang': state.lang, 'X-Source': source }
    if (state.token) header.Authorization = `Bearer ${state.token}`
    const failed = (msg) => {
      uni.showToast({ title: msg, icon: 'none', duration: 2500 })
      reject({ code: -1, msg })
    }

    uni.uploadFile({
      url: BASE + '/upload/image',
      filePath,
      name: 'file',
      formData: { scene },
      header,
      success: (res) => {
        // uploadFile 返回的 data 是字符串；文件超过服务器限制时返回的不是 JSON
        let body = null
        try { body = typeof res.data === 'string' ? JSON.parse(res.data) : res.data } catch (e) { body = null }
        if (body && body.code === 0) return resolve(body.data)
        if (body && body.code === 401) {
          logout(false)
          toLogin()
          return reject(body)
        }
        failed((body && body.msg) || t('common.uploadFailed'))
      },
      fail: () => failed(t('common.networkError')),
    })
  })
}

let jumping = false
export function toLogin() {
  if (jumping) return
  jumping = true
  uni.navigateTo({ url: '/pages/login/index', complete: () => setTimeout(() => { jumping = false }, 800) })
}

/** 需要登录的操作：已登录直接执行，未登录先去登录 */
export function needLogin(fn) {
  if (state.token) return fn()
  toLogin()
}

export const get = (url, data, opt) => request('GET', url, data, opt)
export const post = (url, data, opt) => request('POST', url, data, opt)
export const put = (url, data, opt) => request('PUT', url, data, opt)
export const del = (url, data, opt) => request('DELETE', url, data, opt)
