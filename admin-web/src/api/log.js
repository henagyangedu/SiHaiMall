import http from './request'

export const listLog = (params) => http.get('/log/list', { params })
