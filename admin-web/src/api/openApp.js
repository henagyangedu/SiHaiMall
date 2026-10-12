import http from './request'

// 设置 → 开放接口：给外部系统（ERP、财务、物流）发密钥
export const listOpenApp = () => http.get('/open-app/list')
export const createOpenApp = (data) => http.post('/open-app', data)
export const updateOpenApp = (id, data) => http.put(`/open-app/${id}`, data)
export const resetOpenAppKey = (id) => http.post(`/open-app/${id}/reset-key`)
export const deleteOpenApp = (id) => http.delete(`/open-app/${id}`)
export const listOpenAppLogs = (id, params) => http.get(`/open-app/${id}/logs`, { params })
// 主动推送（Webhook）
export const resetOpenAppWebhookSecret = (id) => http.post(`/open-app/${id}/webhook-secret`)
export const listOpenAppWebhooks = (id, params) => http.get(`/open-app/${id}/webhooks`, { params })
export const resendOpenAppWebhook = (id, webhookId) => http.post(`/open-app/${id}/webhooks/${webhookId}/resend`)
export const testOpenAppWebhook = (id) => http.post(`/open-app/${id}/webhook-test`)
