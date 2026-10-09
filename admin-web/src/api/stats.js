import http from './request'

export const statsOverview = () => http.get('/stats/overview')
export const statsTrend = (days) => http.get('/stats/trend', { params: { days } })
export const statsGoodsRank = (days) => http.get('/stats/goods-rank', { params: { days } })
export const statsBreakdown = (days) => http.get('/stats/breakdown', { params: { days } })
