import http from './request'

export const listSaleRegion = (params) => http.get('/sale-region/list', { params })
export const saleRegionOptions = () => http.get('/sale-region/options')
export const cnDivisions = () => http.get('/sale-region/cn-divisions')
export const createSaleRegion = (data) => http.post('/sale-region', data)
export const updateSaleRegion = (id, data) => http.put(`/sale-region/${id}`, data)
export const setSaleRegionStatus = (id, status) => http.post(`/sale-region/${id}/status`, { status })
export const deleteSaleRegion = (id) => http.delete(`/sale-region/${id}`)
