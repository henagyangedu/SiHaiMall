import http from './request'

export const listBrand = (params) => http.get('/goods/brand/list', { params })
export const brandOptions = () => http.get('/goods/brand/options')
export const createBrand = (data) => http.post('/goods/brand', data)
export const updateBrand = (id, data) => http.put(`/goods/brand/${id}`, data)
export const setBrandStatus = (id, status) => http.post(`/goods/brand/${id}/status`, { status })
export const deleteBrand = (id) => http.delete(`/goods/brand/${id}`)
