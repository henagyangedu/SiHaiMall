import http, { TOKEN_KEY } from './request'

export const listOrder = (params) => http.get('/order/list', { params })
export const orderCounts = () => http.get('/order/counts')
export const getOrder = (id) => http.get(`/order/list/${id}`)
export const setOrderRemark = (id, admin_remark) => http.post(`/order/list/${id}/remark`, { admin_remark })
export const changeOrderPrice = (id, pay_amount) => http.post(`/order/list/${id}/price`, { pay_amount })
export const cancelOrder = (id, reason) => http.post(`/order/list/${id}/cancel`, { reason })
export const receiveOrder = (id) => http.post(`/order/list/${id}/receive`)

/** 导出 CSV：响应不是 JSON，不能走统一拦截器，这里单独用 fetch 带 token 下载 */
export async function exportOrders(params) {
  const base = import.meta.env.VITE_API_BASE || '/admin-api'
  const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)).toString()
  const res = await fetch(`${base}/order/list/export?${qs}`, {
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
  a.download = `orders_${Date.now()}.csv`
  a.click()
  URL.revokeObjectURL(a.href)
}

export const listShipOrder = (params) => http.get('/order/ship/list', { params })
export const expressOptions = () => http.get('/order/ship/express')
export const shipOrder = (id, data) => http.post(`/order/ship/${id}`, data)
export const shippingTrack = (shippingId, refresh = false) => http.get(`/order/ship/record/${shippingId}/track`, { params: refresh ? { refresh: 1 } : {} })
export const editExpress = (shippingId, data) => http.put(`/order/ship/record/${shippingId}`, data)
