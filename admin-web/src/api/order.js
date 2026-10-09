import http from './request'

export const listOrder = (params) => http.get('/order/list', { params })
export const orderCounts = () => http.get('/order/counts')
export const getOrder = (id) => http.get(`/order/list/${id}`)
export const setOrderRemark = (id, admin_remark) => http.post(`/order/list/${id}/remark`, { admin_remark })
export const changeOrderPrice = (id, pay_amount) => http.post(`/order/list/${id}/price`, { pay_amount })
export const cancelOrder = (id, reason) => http.post(`/order/list/${id}/cancel`, { reason })
export const receiveOrder = (id) => http.post(`/order/list/${id}/receive`)

export const listShipOrder = (params) => http.get('/order/ship/list', { params })
export const expressOptions = () => http.get('/order/ship/express')
export const shipOrder = (id, data) => http.post(`/order/ship/${id}`, data)
export const editExpress = (shippingId, data) => http.put(`/order/ship/record/${shippingId}`, data)
