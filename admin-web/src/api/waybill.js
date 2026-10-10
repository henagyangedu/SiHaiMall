import http from './request'

// 电子面单的快递账号（每家快递一条）
export const listWaybillAccount = () => http.get('/waybill/accounts')
export const createWaybillAccount = (data) => http.post('/waybill/accounts', data)
export const updateWaybillAccount = (id, data) => http.put(`/waybill/accounts/${id}`, data)
export const deleteWaybillAccount = (id) => http.delete(`/waybill/accounts/${id}`)
// 发货弹窗用：电子面单能不能用、有哪些快递可选
export const waybillOptions = () => http.get('/waybill/options')
