import http from './request'

export const listDriver = (type) => http.get(`/driver/${type}`)
export const saveDriver = (type, driver, data) => http.put(`/driver/${type}/${driver}`, data)
export const testDriver = (type, driver) => http.post(`/driver/${type}/${driver}/test`)
