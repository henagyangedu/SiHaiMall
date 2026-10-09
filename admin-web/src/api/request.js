import axios from 'axios'
import { ElMessage } from 'element-plus'

export const TOKEN_KEY = 'gm_admin_token'

const http = axios.create({
  baseURL: import.meta.env.VITE_API_BASE || '/admin-api',
  timeout: 30000,
})

http.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) config.headers.Authorization = `Bearer ${token}`
  config.headers['X-Lang'] = 'zh-CN'
  return config
})

let redirecting = false
function toLogin() {
  localStorage.removeItem(TOKEN_KEY)
  if (redirecting) return
  redirecting = true
  window.location.assign('/login')
}

// 后端统一返回 {code, msg, data}：code=0 成功；401 未登录；其它为业务错误
http.interceptors.response.use(
  (res) => {
    const body = res.data
    if (body && body.code === 0) return body.data
    if (body && body.code === 401) {
      ElMessage.error(body.msg || '登录已过期，请重新登录')
      toLogin()
      return Promise.reject(body)
    }
    ElMessage.error((body && body.msg) || '请求失败')
    return Promise.reject(body)
  },
  (err) => {
    ElMessage.error(err.response?.data?.msg || err.message || '网络错误')
    return Promise.reject(err)
  },
)

export default http
