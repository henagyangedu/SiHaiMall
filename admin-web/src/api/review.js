import http from './request'

export const listReview = (params) => http.get('/goods/review/list', { params })
// 隐藏（0）或恢复显示（1）
export const setReviewStatus = (id, status) => http.post(`/goods/review/${id}/status`, { status })
// 商家回复；传空串表示删除回复
export const replyReview = (id, reply) => http.post(`/goods/review/${id}/reply`, { reply })
