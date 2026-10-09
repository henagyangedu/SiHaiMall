import http from './request'

export const categoryTree = () => http.get('/goods/category/tree')
export const createCategory = (data) => http.post('/goods/category', data)
export const updateCategory = (id, data) => http.put(`/goods/category/${id}`, data)
export const setCategoryStatus = (id, status) => http.post(`/goods/category/${id}/status`, { status })
export const deleteCategory = (id) => http.delete(`/goods/category/${id}`)
