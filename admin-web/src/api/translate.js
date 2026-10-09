import http from './request'

// 翻译一批字段到一个目标语言：fields = { title: '原文', description: '<p>…</p>' }
export const translateText = (data) => http.post('/translate', data, { timeout: 180000 })

export const getTranslateSettings = () => http.get('/translate/settings')
export const saveTranslateSettings = (data) => http.put('/translate/settings', data)

export const listGlossary = (params) => http.get('/translate/glossary', { params })
export const createGlossary = (data) => http.post('/translate/glossary', data)
export const updateGlossary = (id, data) => http.put(`/translate/glossary/${id}`, data)
export const deleteGlossary = (id) => http.delete(`/translate/glossary/${id}`)

export const listTranslateLog = (params) => http.get('/translate/log', { params })
