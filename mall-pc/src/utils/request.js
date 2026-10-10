import { ElMessage } from 'element-plus'
import { deviceTimezone } from '@shared/utils/timezone'
import { state, logout } from '@/store'
import { t } from '@/locale'
import router from '@/router'

const BASE = import.meta.env.VITE_API_BASE || '/api'

/**
 * 统一请求。后端返回 {code, msg, data}：code=0 成功，401 未登录，其它为业务错误。
 * 成功时 resolve data；失败默认弹出提示并 reject（传 silent: true 则不弹）。
 * 未登录默认跳到登录页（传 auth: false 表示这个请求未登录也没关系，不跳转）。
 */
export async function request(method, url, data = {}, { silent = false, auth = true } = {}) {
  // X-Source 固定为 pc：后端按它决定可用的支付方式等
  // X-Timezone：后端按它把时间换成买家当地的时间
  const headers = { Accept: 'application/json', 'X-Lang': state.lang, 'X-Currency': state.currency, 'X-Source': 'pc', 'X-Timezone': deviceTimezone() }
  if (state.token) headers.Authorization = `Bearer ${state.token}`

  let full = BASE + url
  const init = { method, headers }
  if (method === 'GET') {
    const qs = new URLSearchParams(Object.entries(data || {}).filter(([, v]) => v !== '' && v !== null && v !== undefined)).toString()
    if (qs) full += (full.includes('?') ? '&' : '?') + qs
  } else {
    headers['Content-Type'] = 'application/json'
    init.body = JSON.stringify(data || {})
  }

  let body = null
  try {
    const res = await fetch(full, init)
    body = await res.json()
  } catch (e) {
    const msg = t('common.networkError')
    if (!silent) ElMessage.error(msg)
    throw { code: -1, msg }
  }

  if (body && body.code === 0) return body.data
  if (body && body.code === 401) {
    logout()
    if (auth) toLogin()
    throw body
  }
  const msg = (body && body.msg) || t('common.requestFailed')
  if (!silent) ElMessage.error(msg)
  throw body || { code: -1, msg }
}

/**
 * 上传一张图片（File 对象），成功时 resolve {id, url, ...}。scene：refund 售后凭证 / avatar 头像。
 * 失败时弹出提示并 reject。
 */
export async function upload(file, scene) {
  const headers = { Accept: 'application/json', 'X-Lang': state.lang, 'X-Source': 'pc' }
  if (state.token) headers.Authorization = `Bearer ${state.token}`
  const form = new FormData()
  form.append('file', file)
  form.append('scene', scene)

  let body = null
  try {
    // 文件超过服务器限制时返回的可能不是 JSON
    body = await (await fetch(BASE + '/upload/image', { method: 'POST', headers, body: form })).json()
  } catch (e) {
    body = null
  }
  if (body && body.code === 0) return body.data
  if (body && body.code === 401) {
    logout()
    toLogin()
    throw body
  }
  const msg = (body && body.msg) || t('common.uploadFailed')
  ElMessage.error(msg)
  throw body || { code: -1, msg }
}

/** 去登录页，登录后回到当前页面 */
export function toLogin() {
  const current = router.currentRoute.value
  if (current.name === 'login') return
  router.push({ name: 'login', query: { redirect: current.fullPath } })
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
