import http from './request'

export const listMessageTemplate = () => http.get('/message/list')
export const saveMessageTemplate = (scene, data) => http.put(`/message/${scene}`, data)
export const testMessageTemplate = (scene, data) => http.post(`/message/${scene}/test`, data)
