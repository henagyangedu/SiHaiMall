import http, { TOKEN_KEY } from './request'

export const listMember = (params) => http.get('/member/list', { params })
export const getMember = (id) => http.get(`/member/list/${id}`)
export const updateMember = (id, data) => http.put(`/member/list/${id}`, data)
export const setMemberStatus = (id, status) => http.post(`/member/list/${id}/status`, { status })
export const resetMemberPassword = (id, password) => http.post(`/member/list/${id}/password`, { password })
export const adjustMemberPoints = (id, data) => http.post(`/member/list/${id}/points`, data)
export const adjustMemberBalance = (id, data) => http.post(`/member/list/${id}/balance`, data)
export const memberBalanceLog = (id, params) => http.get(`/member/list/${id}/balance-log`, { params })

export const listLevel = () => http.get('/member/level/list')
export const levelOptions = () => http.get('/member/level/options')
export const createLevel = (data) => http.post('/member/level', data)
export const updateLevel = (id, data) => http.put(`/member/level/${id}`, data)
export const deleteLevel = (id) => http.delete(`/member/level/${id}`)

export const listPointLog = (params) => http.get('/member/points', { params })

/** 导出 CSV：响应不是 JSON，不能走统一拦截器，这里单独用 fetch 带 token 下载 */
export async function exportMembers(params) {
  const base = import.meta.env.VITE_API_BASE || '/admin-api'
  const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)).toString()
  const res = await fetch(`${base}/member/list/export?${qs}`, {
    headers: { Authorization: `Bearer ${localStorage.getItem(TOKEN_KEY) || ''}`, 'X-Lang': 'zh-CN' },
  })
  const type = res.headers.get('content-type') || ''
  if (!res.ok || type.includes('application/json')) {
    let msg = '导出失败'
    try { msg = (await res.json()).msg || msg } catch (e) { /* 忽略 */ }
    throw new Error(msg)
  }
  const blob = await res.blob()
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = `members_${Date.now()}.csv`
  a.click()
  URL.revokeObjectURL(a.href)
}
