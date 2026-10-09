import http from './request'

export const listLanguage = () => http.get('/language/list')
export const languageOptions = () => http.get('/language/options')
export const createLanguage = (data) => http.post('/language', data)
export const updateLanguage = (id, data) => http.put(`/language/${id}`, data)
export const setDefaultLanguage = (id) => http.post(`/language/${id}/default`)
export const deleteLanguage = (id) => http.delete(`/language/${id}`)
