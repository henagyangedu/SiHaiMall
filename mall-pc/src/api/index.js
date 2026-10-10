import { get, post, put, del, upload } from '@/utils/request'

// 接口与手机端（mall-uni/src/api）一致，后端不区分端；这里只列 PC 端已经用到的

// ---- 启动配置、内容
export const getInit = () => get('/config/init', {}, { auth: false, silent: true })
export const getCnDivisions = () => get('/config/cn-divisions')
export const getAds = (position) => get('/ads', { position }, { auth: false, silent: true })
export const getArticles = (params) => get('/articles', params, { auth: false })
export const getArticle = (id) => get(`/articles/${id}`, {}, { auth: false, silent: true })
export const getHelp = () => get('/help', {}, { auth: false })
export const getProtocols = () => get('/protocols', {}, { auth: false, silent: true })
export const getProtocol = (key) => get(`/protocols/${key}`, {}, { auth: false, silent: true })

// ---- 商品
export const getCategories = () => get('/categories', {}, { auth: false })
export const getGoodsList = (params) => get('/goods', params, { auth: false })
export const getGoods = (id) => get(`/goods/${id}`, {}, { auth: false, silent: true })
export const getGoodsReviews = (id, params) => get(`/goods/${id}/reviews`, params, { auth: false })
export const createReview = (orderNo, data) => post(`/orders/${orderNo}/reviews`, data)

// ---- 登录注册
export const login = (data) => post('/auth/login', data, { auth: false })
export const register = (data) => post('/auth/register', data, { auth: false })
export const loginBySms = (data) => post('/auth/login-sms', data, { auth: false })
export const sendSmsCode = (data) => post('/auth/sms-code', data, { auth: false })
export const sendEmailCode = (data) => post('/auth/email-code', data, { auth: false })
export const logoutApi = () => post('/auth/logout', {}, { auth: false, silent: true })
export const getProfile = () => get('/member/profile', {}, { auth: false, silent: true })
export const updateProfile = (data) => put('/member/profile', data)
export const changePassword = (data) => put('/member/password', data)
export const bindContact = (data) => post('/member/bind', data)
export const getPointLogs = (params) => get('/member/points', params)
export const getCoupons = (params) => get('/coupons', params, { auth: false, silent: true })
export const receiveCoupon = (id) => post(`/coupons/${id}/receive`)
export const getMyCoupons = (params) => get('/member/coupons', params)
export const getFavorites = (params) => get('/favorites', params)
export const addFavorite = (goodsId) => post(`/favorites/${goodsId}`)
export const removeFavorite = (goodsId) => del(`/favorites/${goodsId}`)
// 到货提醒：缺货的规格可以订阅，补货后通知一次
export const getStockSubscribes = (params) => get('/stock-subscribes', params)
export const addStockSubscribe = (skuId) => post(`/stock-subscribes/${skuId}`)
export const removeStockSubscribe = (skuId) => del(`/stock-subscribes/${skuId}`)

// 站内信（消息中心）
export const getMessages = (params) => get('/messages', params)
export const getUnreadCount = () => get('/messages/unread-count', {}, { auth: false, silent: true })
export const readMessage = (id) => post(`/messages/${id}/read`)
export const readAllMessages = () => post('/messages/read-all')
export const deleteMessage = (id) => del(`/messages/${id}`)
export const getSignIn = () => get('/member/signin')
export const signIn = () => post('/member/signin')
export const resetPasswordBySms = (data) => post('/auth/reset-password', data, { auth: false })
export const resetPasswordByEmail = (data) => post('/auth/reset-password-email', data, { auth: false })
export const uploadImage = (file, scene) => upload(file, scene)

// ---- 购物车
export const getCart = (opt) => get('/cart', {}, opt)
export const addCart = (sku_id, qty) => post('/cart', { sku_id, qty })
export const setCartQty = (id, qty) => put(`/cart/${id}`, { qty })
export const removeCart = (ids) => post('/cart/remove', { ids })
export const clearInvalidCart = () => post('/cart/clear-invalid')

// ---- 地址
export const getAddresses = () => get('/addresses')
export const createAddress = (data) => post('/addresses', data)
export const updateAddress = (id, data) => put(`/addresses/${id}`, data)
export const deleteAddress = (id) => del(`/addresses/${id}`)

// ---- 订单与支付
export const checkout = (data) => post('/orders/checkout', data)
export const createOrder = (data) => post('/orders', data)
export const getOrders = (params) => get('/orders', params)
export const getOrder = (orderNo) => get(`/orders/${orderNo}`)
export const getOrderTracks = (orderNo) => get(`/orders/${orderNo}/tracks`, {}, { silent: true })
export const cancelOrder = (orderNo, reason = '') => post(`/orders/${orderNo}/cancel`, { reason })
export const receiveOrder = (orderNo) => post(`/orders/${orderNo}/receive`)
export const getPayChannels = (orderNo) => get(`/orders/${orderNo}/pay/channels`)
export const startPay = (orderNo, data) => post(`/orders/${orderNo}/pay`, data)
export const confirmPay = (tradeNo) => get(`/pay/${tradeNo}/confirm`, {}, { silent: true })

// ---- 售后
export const getRefundable = (orderNo) => get(`/orders/${orderNo}/refundable`)
export const applyRefund = (orderNo, data) => post(`/orders/${orderNo}/refunds`, data)
export const getRefunds = (params) => get('/refunds', params)
export const getRefund = (refundNo) => get(`/refunds/${refundNo}`)
export const cancelRefund = (refundNo) => post(`/refunds/${refundNo}/cancel`)
export const submitRefundReturn = (refundNo, data) => post(`/refunds/${refundNo}/return`, data)

// ---- 分销中心
export const getDistSummary = () => get('/distribution/summary')
export const applyDistributor = (data) => post('/distribution/apply', data)
export const getCommissions = (params) => get('/distribution/commissions', params)
export const getTeam = (params) => get('/distribution/team', params)
export const getWithdraws = (params) => get('/distribution/withdraws', params)
export const applyWithdraw = (data) => post('/distribution/withdraws', data)

// 订单发票：查看（能不能申请、开票金额、现在的状态）、申请或修改、撤销
export const getInvoice = (orderNo) => get(`/orders/${orderNo}/invoice`)
export const applyInvoice = (orderNo, data) => post(`/orders/${orderNo}/invoice`, data)
export const cancelInvoice = (orderNo) => del(`/orders/${orderNo}/invoice`)
