import http from './request'

export const menuTree = () => http.get('/menu/tree')
export const createMenu = (data) => http.post('/menu', data)
export const updateMenu = (id, data) => http.put(`/menu/${id}`, data)
export const deleteMenu = (id) => http.delete(`/menu/${id}`)
