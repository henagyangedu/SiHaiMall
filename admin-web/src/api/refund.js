import http from './request'

export const listRefund = (params) => http.get('/order/refund/list', { params })
export const refundCounts = () => http.get('/order/refund/counts')
export const getRefund = (id) => http.get(`/order/refund/${id}`)
export const auditRefund = (id, data) => http.post(`/order/refund/${id}/audit`, data)
export const confirmRefundReturn = (id) => http.post(`/order/refund/${id}/confirm`)
// 成功时后端的提示文案在 msg 里，拦截器只返回 data，这里用 done 区分“已完成”和“渠道处理中”
export const payRefund = (id, offline) => http.post(`/order/refund/${id}/pay`, { offline: offline ? 1 : 0 })
