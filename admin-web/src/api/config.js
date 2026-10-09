import http from './request'

export const getConfig = (group) => http.get(`/config/${group}`)
export const saveConfig = (group, data) => http.put(`/config/${group}`, data)
