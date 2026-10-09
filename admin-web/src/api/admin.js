import http from './request'

export const listAdmin = (params) => http.get('/admin/list', { params })
export const createAdmin = (data) => http.post('/admin', data)
export const updateAdmin = (id, data) => http.put(`/admin/${id}`, data)
export const deleteAdmin = (id) => http.delete(`/admin/${id}`)
export const resetAdmin = (id, data) => http.post(`/admin/${id}/reset`, data)
