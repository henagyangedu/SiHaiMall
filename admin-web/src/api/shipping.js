import http from './request'

export const listShippingTemplate = (params) => http.get('/shipping-template/list', { params })
export const shippingTemplateOptions = () => http.get('/shipping-template/options')
export const getShippingTemplate = (id) => http.get(`/shipping-template/${id}`)
export const createShippingTemplate = (data) => http.post('/shipping-template', data)
export const updateShippingTemplate = (id, data) => http.put(`/shipping-template/${id}`, data)
export const deleteShippingTemplate = (id) => http.delete(`/shipping-template/${id}`)
