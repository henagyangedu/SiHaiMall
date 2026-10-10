import http from './request'

// 发票管理
export const listInvoice = (params) => http.get('/order/invoice/list', { params })
export const issueInvoice = (id, data) => http.post(`/order/invoice/${id}/issue`, data)
export const rejectInvoice = (id, reason) => http.post(`/order/invoice/${id}/reject`, { reason })
export const voidInvoice = (id, reason) => http.post(`/order/invoice/${id}/void`, { reason })
