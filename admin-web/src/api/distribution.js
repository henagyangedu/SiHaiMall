import http from './request'

export const listDistributor = (params) => http.get('/distribution/distributor/list', { params })
export const auditDistributor = (id, data) => http.post(`/distribution/distributor/${id}/audit`, data)
export const setDistributorStatus = (id, status) => http.post(`/distribution/distributor/${id}/status`, { status })
export const appointDistributor = (id) => http.post(`/distribution/distributor/${id}/appoint`)
export const getRelation = (id) => http.get(`/distribution/relation/${id}`)
export const setRelation = (id, parent_id) => http.put(`/distribution/relation/${id}`, { parent_id })

export const listCommission = (params) => http.get('/distribution/commission/list', { params })

export const listWithdraw = (params) => http.get('/distribution/withdraw/list', { params })
export const auditWithdraw = (id, data) => http.post(`/distribution/withdraw/${id}/audit`, data)
export const payWithdraw = (id, pay_remark) => http.post(`/distribution/withdraw/${id}/pay`, { pay_remark })
