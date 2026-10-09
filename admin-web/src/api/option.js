import http from './request'

// 启用的币种：[{code,name,symbol,rate,decimals,is_base}]
export const currencyOptions = () => http.get('/options/currencies')
