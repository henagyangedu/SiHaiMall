export default {
  common: {
    requestFailed: '请求失败', networkError: '网络错误，请稍后再试', loading: '加载中…', noMore: '没有更多了', empty: '暂无数据',
    confirm: '确定', cancel: '取消', save: '保存', delete: '删除', edit: '编辑', submit: '提交', ok: '好的', saved: '保存成功', deleted: '已删除',
    more: '查看更多', all: '全部', retry: '重试',
  },
  tab: { home: '首页', category: '分类', cart: '购物车', user: '我的' },
  home: { search: '搜索商品', notice: '公告', recommend: '为你推荐', sold: '已售 {n}', soldOut: '已售罄' },
  category: { all: '全部商品', sortDefault: '综合', sortSales: '销量', sortPrice: '价格', sortNew: '新品' },
  goods: {
    detail: '商品详情', stock: '库存 {n}', select: '请选择', selected: '已选', qty: '数量', addCart: '加入购物车', buyNow: '立即购买',
    added: '已加入购物车', chooseSpec: '请选择规格', noStock: '库存不足', description: '图文详情', offShelf: '商品不存在或已下架',
  },
  cart: {
    title: '购物车', empty: '购物车是空的', goShopping: '去逛逛', selectAll: '全选', total: '合计', checkout: '去结算（{n}）',
    invalid: '已失效', clearInvalid: '清除失效商品', onlyLeft: '仅剩 {n} 件', chooseFirst: '请先选择商品', removeConfirm: '确定删除选中的商品吗？',
  },
  login: {
    title: '登录', register: '注册', account: '邮箱或手机号', password: '密码', nickname: '昵称（可选）', code: '验证码', getCode: '获取验证码',
    resend: '{n} 秒后重发', inviteCode: '邀请码（可选）', submitLogin: '登录', submitRegister: '注册', toRegister: '没有账号？去注册', toLogin: '已有账号？去登录',
    needAccount: '请输入邮箱或手机号', needPassword: '密码至少 6 位', codeSent: '验证码已发送', welcome: '欢迎回来', agree: '注册即表示同意',
  },
  checkout: {
    title: '确认订单', addAddress: '请添加收货地址', goods: '商品金额', shipping: '运费', discount: '会员优惠', points: '积分抵扣',
    usePoints: '使用 {n} 积分抵扣 {amount}', remark: '订单备注（可选）', pay: '应付', submit: '提交订单', region: '按“{name}”区域定价',
    needAddress: '请先选择收货地址', free: '免运费',
  },
  order: {
    list: '我的订单', detail: '订单详情', tabAll: '全部', tabUnpaid: '待支付', tabToShip: '待发货', tabShipped: '待收货', tabFinished: '已完成',
    no: '订单号', createdAt: '下单时间', payNow: '去支付', cancel: '取消订单', receive: '确认收货', cancelConfirm: '确定取消这个订单吗？',
    receiveConfirm: '确认已经收到商品？', cancelled: '订单已取消', received: '已确认收货', count: '共 {n} 件', payAmount: '实付',
    address: '收货信息', express: '物流信息', payChannel: '选择支付方式', noChannel: '暂时没有可用的支付方式，请联系客服', expireAt: '请在 {time} 前支付',
    paidSuccess: '支付成功', payPending: '支付处理中，请稍后在订单里查看', remark: '备注',
  },
  address: {
    list: '收货地址', add: '新增地址', edit: '编辑地址', name: '收件人', phone: '手机号', country: '国家/地区', province: '省/州', city: '城市',
    district: '区/县', detail: '详细地址', postcode: '邮编', setDefault: '设为默认地址', default: '默认', empty: '还没有收货地址',
    choose: '请选择', deleteConfirm: '确定删除这个地址吗？', needName: '请填写收件人', needPhone: '请填写手机号', needDetail: '请填写详细地址', needRegion: '请选择省市',
  },
  user: {
    title: '我的', notLogin: '点击登录', orders: '我的订单', address: '收货地址', language: '语言', currency: '币种', logout: '退出登录',
    logoutConfirm: '确定退出登录吗？', points: '积分', balance: '余额',
  },
}
