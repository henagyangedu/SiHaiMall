import http from './request'

export const listCurrency = () => http.get('/currency/list')
export const createCurrency = (data) => http.post('/currency', data)
export const updateCurrency = (id, data) => http.put(`/currency/${id}`, data)
export const updateCurrencyRate = (id, rate) => http.put(`/currency/${id}/rate`, { rate })
export const setBaseCurrency = (id) => http.post(`/currency/${id}/base`)
export const deleteCurrency = (id) => http.delete(`/currency/${id}`)
