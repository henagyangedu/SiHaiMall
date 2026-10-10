import http from './request'

// 搭配套餐
export const listBundle = (params) => http.get('/marketing/bundle/list', { params })
// 选商品用：按名称找在售商品的规格
export const bundleSkuOptions = (keyword) => http.get('/marketing/bundle/skus', { params: { keyword } })
export const createBundle = (data) => http.post('/marketing/bundle', data)
export const updateBundle = (id, data) => http.put(`/marketing/bundle/${id}`, data)
export const setBundleStatus = (id, status) => http.post(`/marketing/bundle/${id}/status`, { status })
export const deleteBundle = (id) => http.delete(`/marketing/bundle/${id}`)
