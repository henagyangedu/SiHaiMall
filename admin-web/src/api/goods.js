import http from './request'

export const listGoods = (params) => http.get('/goods/list', { params })
export const getGoods = (id) => http.get(`/goods/${id}`)
export const createGoods = (data) => http.post('/goods', data)
export const updateGoods = (id, data) => http.put(`/goods/${id}`, data)
export const deleteGoods = (id) => http.delete(`/goods/${id}`)
export const setGoodsSale = (id, status) => http.post(`/goods/${id}/sale`, { status })
export const adjustGoodsStock = (id, data) => http.post(`/goods/${id}/stock`, data)
export const listStockLog = (params) => http.get('/goods/stocklog/list', { params })
