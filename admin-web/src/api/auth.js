import http from './request'

export const login = (data) => http.post('/auth/login', data)
export const getInfo = () => http.get('/auth/info')
export const logout = () => http.post('/auth/logout')
export const changePassword = (data) => http.post('/auth/password', data)
