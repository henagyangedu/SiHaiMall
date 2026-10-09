import { get, post, put, del } from '@/utils/request'

// 接口与手机端（mall-uni/src/api）一致，后端不区分端；这里只列 PC 端已经用到的

// ---- 启动配置、内容
export const getInit = () => get('/config/init', {}, { auth: false, silent: true })
export const getCnDivisions = () => get('/config/cn-divisions')
export const getAds = (position) => get('/ads', { position }, { auth: false, silent: true })
export const getArticles = (params) => get('/articles', params, { auth: false })

// ---- 商品
export const getCategories = () => get('/categories', {}, { auth: false })
export const getGoodsList = (params) => get('/goods', params, { auth: false })
export const getGoods = (id) => get(`/goods/${id}`, {}, { auth: false, silent: true })

// ---- 登录注册
export const login = (data) => post('/auth/login', data, { auth: false })
export const register = (data) => post('/auth/register', data, { auth: false })
export const loginBySms = (data) => post('/auth/login-sms', data, { auth: false })
export const sendSmsCode = (data) => post('/auth/sms-code', data, { auth: false })
export const sendEmailCode = (data) => post('/auth/email-code', data, { auth: false })
export const logoutApi = () => post('/auth/logout', {}, { auth: false, silent: true })
export const getProfile = () => get('/member/profile', {}, { auth: false, silent: true })
export const updateProfile = (data) => put('/member/profile', data)

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
