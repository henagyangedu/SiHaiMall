import http from './request'

export const listTrade = (params) => http.get('/order/trade/list', { params })
export const listNotify = (params) => http.get('/order/notify/list', { params })
export const showNotify = (id) => http.get(`/order/notify/${id}`)
export const retryNotify = (id) => http.post(`/order/notify/${id}/retry`)
