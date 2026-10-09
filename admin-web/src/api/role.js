import http from './request'

export const listRole = (params) => http.get('/role/list', { params })
export const roleOptions = () => http.get('/role/options')
export const getRole = (id) => http.get(`/role/${id}`)
export const createRole = (data) => http.post('/role', data)
export const updateRole = (id, data) => http.put(`/role/${id}`, data)
export const deleteRole = (id) => http.delete(`/role/${id}`)
