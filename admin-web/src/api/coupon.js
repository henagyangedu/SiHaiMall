import http from './request'

export const listCoupon = (params) => http.get('/marketing/coupon/list', { params })
export const createCoupon = (data) => http.post('/marketing/coupon', data)
export const updateCoupon = (id, data) => http.put(`/marketing/coupon/${id}`, data)
export const setCouponStatus = (id, status) => http.post(`/marketing/coupon/${id}/status`, { status })
export const deleteCoupon = (id) => http.delete(`/marketing/coupon/${id}`)
// 发放给指定会员：members 是会员 ID、账号、手机号或邮箱的数组
export const grantCoupon = (id, members) => http.post(`/marketing/coupon/${id}/grant`, { members })
export const couponRecords = (id, params) => http.get(`/marketing/coupon/${id}/records`, { params })
