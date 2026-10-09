import http from './request'

export const listRefund = (params) => http.get('/order/refund/list', { params })
export const refundCounts = () => http.get('/order/refund/counts')
export const getRefund = (id) => http.get(`/order/refund/${id}`)
// 商家代买家发起售后（参数是订单 ID）
export const orderRefundable = (orderId) => http.get(`/order/list/${orderId}/refundable`)
export const applyRefundForOrder = (orderId, data) => http.post(`/order/list/${orderId}/refund`, data)
export const auditRefund = (id, data) => http.post(`/order/refund/${id}/audit`, data)
export const confirmRefundReturn = (id) => http.post(`/order/refund/${id}/confirm`)
// 成功时后端的提示文案在 msg 里，拦截器只返回 data，这里用 done 区分“已完成”和“渠道处理中”
export const payRefund = (id, offline) => http.post(`/order/refund/${id}/pay`, { offline: offline ? 1 : 0 })
