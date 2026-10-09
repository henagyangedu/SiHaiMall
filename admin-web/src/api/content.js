import http from './request'

// 文章 / 公告 / 帮助中心共用，type 取 article | notice | help
export const listArticle = (type, params) => http.get(`/content/${type}/list`, { params })
export const getArticle = (type, id) => http.get(`/content/${type}/${id}`)
export const createArticle = (type, data) => http.post(`/content/${type}`, data)
export const updateArticle = (type, id, data) => http.put(`/content/${type}/${id}`, data)
export const setArticleStatus = (type, id, status) => http.post(`/content/${type}/${id}/status`, { status })
export const setArticleTop = (type, id, status) => http.post(`/content/${type}/${id}/top`, { status })
export const deleteArticle = (type, id) => http.delete(`/content/${type}/${id}`)

// 分类（公告没有）
export const listArticleCategory = (type) => http.get(`/content/${type}/category/list`)
export const createArticleCategory = (type, data) => http.post(`/content/${type}/category`, data)
export const updateArticleCategory = (type, id, data) => http.put(`/content/${type}/category/${id}`, data)
export const deleteArticleCategory = (type, id) => http.delete(`/content/${type}/category/${id}`)

// 广告位与广告
export const listAdPosition = () => http.get('/content/ad/position/list')
export const createAdPosition = (data) => http.post('/content/ad/position', data)
export const updateAdPosition = (id, data) => http.put(`/content/ad/position/${id}`, data)
export const deleteAdPosition = (id) => http.delete(`/content/ad/position/${id}`)
export const listAd = (position_id) => http.get('/content/ad/list', { params: { position_id } })
export const createAd = (data) => http.post('/content/ad', data)
export const updateAd = (id, data) => http.put(`/content/ad/${id}`, data)
export const setAdStatus = (id, status) => http.post(`/content/ad/${id}/status`, { status })
export const deleteAd = (id) => http.delete(`/content/ad/${id}`)
