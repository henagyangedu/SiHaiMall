import http from './request'

export const listCurrency = () => http.get('/currency/list')
export const createCurrency = (data) => http.post('/currency', data)
export const updateCurrency = (id, data) => http.put(`/currency/${id}`, data)
export const updateCurrencyRate = (id, rate) => http.put(`/currency/${id}/rate`, { rate })
export const setCurrencyRateMode = (id, mode) => http.put(`/currency/${id}/rate-mode`, { mode })
export const getRateConfig = () => http.get('/currency/rate-config')
export const saveRateConfig = (data) => http.put('/currency/rate-config', data)
export const syncRates = () => http.post('/currency/rate-sync')
export const setBaseCurrency = (id) => http.post(`/currency/${id}/base`)
export const deleteCurrency = (id) => http.delete(`/currency/${id}`)
