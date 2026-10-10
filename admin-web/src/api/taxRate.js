import http from './request'

export const listTaxRate = () => http.get('/tax-rate/list')
export const createTaxRate = (data) => http.post('/tax-rate', data)
export const updateTaxRate = (id, data) => http.put(`/tax-rate/${id}`, data)
export const deleteTaxRate = (id) => http.delete(`/tax-rate/${id}`)
