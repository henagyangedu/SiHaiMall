<script setup>
import { ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getOrder, getOrderTracks, cancelOrder, receiveOrder, getPayChannels, startPay, confirmPay, getWechatOauthUrl } from '@/api'
import { money } from '@/utils/app'
import { usePageTitle, confirm, toast, nav } from '@/utils/page'

const { t } = useI18n()
usePageTitle('order.detail')

const orderNo = ref('')
const order = ref(null)
// 物流轨迹：点“查看物流”才加载（按包裹顺序，和 order.shippings 一一对应）
const tracks = ref(null)
const tracksLoading = ref(false)
async function loadTracks() {
  if (tracksLoading.value) return
  tracksLoading.value = true
  try {
    tracks.value = await getOrderTracks(orderNo.value)
  } catch (e) {
    toast(t('order.trackFailed'))
  } finally {
    tracksLoading.value = false
  }
}
let autoPay = false
// #ifdef H5
// 在微信里打开的网页用微信支付：要先跳到微信确认付款人身份（网页授权），微信再带着 code 跳回本页，然后才能调起收银台
const IN_WECHAT = /MicroMessenger/i.test(navigator.userAgent)
const WX_OAUTH_STATE = 'gmwxpay'
// 从微信授权跳回来时带的 code（只能用一次）
let wxOauthCode = ''
async function payInWechat(code) {
  const r = await startPay(orderNo.value, { channel: 'wechat', wx_code: code })
  uni.setStorageSync(tradeKey(), r.trade_no)
  await new Promise((resolve) => {
    const run = () => window.WeixinJSBridge.invoke('getBrandWCPayRequest', r.client_params, () => resolve())
    if (window.WeixinJSBridge) run()
    else document.addEventListener('WeixinJSBridgeReady', run, { once: true })
  })
  // 付完或取消都回来向后端确认一次（load 里会用记下的支付单号查）
  load()
}
// #endif

// 发起支付后把支付单号记在本地：买家从收银台回来时用它向后端确认是否到账
const tradeKey = () => `gm_trade_${orderNo.value}`

async function load() {
  const tradeNo = uni.getStorageSync(tradeKey())
  if (tradeNo) {
    try {
      const r = await confirmPay(tradeNo)
      if (r.status === 'success') toast(t('order.paidSuccess'))
      else if (r.status === 'pending') toast(t('order.payPending'))
      if (r.status !== 'pending') uni.removeStorageSync(tradeKey())
    } catch (e) { /* 确认失败不影响看订单 */ }
  }
  order.value = await getOrder(orderNo.value)
  if (!order.value.can_pay) uni.removeStorageSync(tradeKey())
}

async function onPay() {
  const channels = await getPayChannels(orderNo.value)
  if (!channels.length) return uni.showModal({ content: t('order.noChannel'), showCancel: false, confirmText: t('common.ok') })
  uni.showActionSheet({
    title: t('order.payChannel'),
    itemList: channels.map((c) => c.name),
    success: async ({ tapIndex }) => {
      let returnUrl = ''
      // #ifdef H5
      returnUrl = window.location.href.replace(/([?&])pay=1/, '$1')
      // #endif
      const channel = channels[tapIndex].channel
      // #ifdef H5
      if (channel === 'wechat' && IN_WECHAT) {
        const o = await getWechatOauthUrl(returnUrl)
        // 没配公众号时拿不到授权地址：照常往下发起支付，后端会给出“请用手机浏览器打开”的提示
        if (o.url) { window.location.href = o.url; return }
      }
      // #endif
      let wxCode = ''
      // #ifdef MP-WEIXIN
      // 微信支付要知道付款人是谁：取一次登录凭证，后端用它换 openid（只用于这次付款）
      if (channel === 'wechat') wxCode = await new Promise((resolve) => uni.login({ provider: 'weixin', success: (x) => resolve(x.code || ''), fail: () => resolve('') }))
      // #endif
      const r = await startPay(orderNo.value, { channel, return_url: returnUrl, wx_code: wxCode })
      uni.setStorageSync(tradeKey(), r.trade_no)
      // #ifdef H5
      if (r.redirect_url) window.location.href = r.redirect_url
      // #endif
      // #ifdef MP-WEIXIN
      if (r.client_params && r.client_params.package) {
        // 调起微信收银台；付完或取消后都回来向后端确认一次（load 里会用记下的支付单号查）
        uni.requestPayment({ provider: 'wxpay', ...r.client_params, complete: () => load() })
      }
      // #endif
    },
  })
}
async function onCancel() {
  if (!(await confirm(t('order.cancelConfirm')))) return
  await cancelOrder(orderNo.value)
  toast(t('order.cancelled'))
  load()
}
async function onReceive() {
  if (!(await confirm(t('order.receiveConfirm')))) return
  await receiveOrder(orderNo.value)
  toast(t('order.received'))
  load()
}

onLoad((q) => {
  orderNo.value = q.order_no || ''
  autoPay = q.pay === '1'
  // #ifdef H5
  // 微信授权跳回来的地址形如 /?code=xxx&state=gmwxpay#/pages/order/detail?order_no=...，code 在 # 前面
  const back = new URLSearchParams(window.location.search)
  if (back.get('state') === WX_OAUTH_STATE && back.get('code')) {
    wxOauthCode = back.get('code')
    // code 只能用一次：从地址里去掉，免得刷新页面时再拿它付款
    window.history.replaceState(window.history.state, '', window.location.pathname + window.location.hash)
  }
  // #endif
})
onShow(async () => {
  await load()
  // #ifdef H5
  if (wxOauthCode) {
    const code = wxOauthCode
    wxOauthCode = ''
    autoPay = false
    if (order.value?.can_pay) payInWechat(code)
    return
  }
  // #endif
  // 从结算页或订单列表点“去支付”进来：直接弹出支付方式
  if (autoPay && order.value?.can_pay) { autoPay = false; onPay() }
})
</script>

<template>
  <view v-if="order" class="page">
    <view class="status">
      <view class="st">{{ t(`orderStatus.${order.status}`) }}</view>
      <view v-if="order.can_pay && order.expire_at" class="tip">{{ t('order.expireAt', { time: order.expire_at }) }}</view>
      <view v-if="order.cancel_reason" class="tip">{{ order.cancel_reason }}</view>
    </view>

    <view v-if="order.address" class="card">
      <view class="section">{{ t('order.address') }}</view>
      <view>{{ order.address.name }}　+{{ order.address.phone_area }} {{ order.address.phone }}</view>
      <view class="sub">{{ [order.address.province, order.address.city, order.address.district, order.address.address, order.address.address2].filter(Boolean).join(' ') }}</view>
    </view>

    <view v-if="order.shippings.length" class="card">
      <view class="section">{{ t('order.express') }}</view>
      <view v-for="(s, i) in order.shippings" :key="i" class="parcel">
        <view class="kv">
          <text>{{ s.express_name }}<text v-if="tracks && tracks[i]" class="tstatus">　{{ t(`trackStatus.${tracks[i].status}`) }}</text></text>
          <text selectable>{{ s.express_no }}</text>
        </view>
        <template v-if="tracks && tracks[i]">
          <view v-if="tracks[i].message" class="sub">{{ tracks[i].message }}</view>
          <view v-for="(x, k) in tracks[i].traces" :key="k" class="trace" :class="{ first: k === 0 }">
            <view class="dot" />
            <view class="tbody"><view>{{ x.context }}</view><view class="sub">{{ x.time }}</view></view>
          </view>
        </template>
      </view>
      <view v-if="!tracks" class="track-btn" @click="loadTracks">{{ tracksLoading ? t('common.loading') : t('order.viewTrack') }}</view>
    </view>

    <view class="card">
      <view v-for="(i, k) in order.items" :key="k" class="item">
        <image :src="i.image" mode="aspectFill" class="thumb" />
        <view class="body">
          <view class="ellipsis-2">{{ i.title }}</view>
          <view class="sub ellipsis">{{ i.spec_text }}</view>
        </view>
        <view class="right">
          <view>{{ money(i.price, order.currency) }}</view><view class="sub">× {{ i.qty }}</view>
          <view v-if="i.can_review" class="rv-btn" @click="nav(`/pages/review/create?order_no=${order.order_no}&item_id=${i.item_id}`)">{{ t('review.toReview') }}</view>
          <view v-else-if="i.reviewed" class="sub rv-done">{{ t('review.reviewed') }}</view>
        </view>
      </view>
      <view class="kv"><text class="sub">{{ t('checkout.goods') }}</text><text>{{ money(order.goods_amount, order.currency) }}</text></view>
      <view class="kv"><text class="sub">{{ t('checkout.shipping') }}</text><text>{{ money(order.shipping_fee, order.currency) }}</text></view>
      <view v-if="Number(order.discount_amount) > 0" class="kv"><text class="sub">{{ t('checkout.discount') }}</text><text>-{{ money(order.discount_amount, order.currency) }}</text></view>
      <view v-if="Number(order.points_amount) > 0" class="kv"><text class="sub">{{ t('checkout.points') }}</text><text>-{{ money(order.points_amount, order.currency) }}</text></view>
      <view class="kv total"><text>{{ t('order.payAmount') }}</text><text class="price">{{ money(order.pay_amount, order.currency) }}</text></view>
    </view>

    <view class="card">
      <view class="kv"><text class="sub">{{ t('order.no') }}</text><text selectable>{{ order.order_no }}</text></view>
      <view class="kv"><text class="sub">{{ t('order.createdAt') }}</text><text>{{ order.created_at }}</text></view>
      <view v-if="order.remark" class="kv"><text class="sub">{{ t('order.remark') }}</text><text>{{ order.remark }}</text></view>
    </view>

    <view v-if="order.can_pay || order.can_cancel || order.can_receive || order.can_refund" class="bar safe-bottom">
      <view v-if="order.can_refund" class="btn small gray" @click="nav(`/pages/refund/apply?order_no=${order.order_no}&currency=${order.currency}`)">{{ t('refund.apply') }}</view>
      <view v-if="order.can_cancel" class="btn small gray" @click="onCancel">{{ t('order.cancel') }}</view>
      <view v-if="order.can_pay" class="btn small" @click="onPay">{{ t('order.payNow') }}</view>
      <view v-if="order.can_receive" class="btn small" @click="onReceive">{{ t('order.receive') }}</view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding-bottom: 160rpx; }
.status { background: var(--theme); color: #fff; padding: 40rpx 32rpx; }
.st { font-size: 36rpx; font-weight: 600; }
.tip { font-size: 24rpx; opacity: 0.9; margin-top: 8rpx; }
.section { font-weight: 600; margin-bottom: 12rpx; }
.parcel + .parcel { border-top: 2rpx solid var(--line); margin-top: 12rpx; padding-top: 12rpx; }
.tstatus { color: var(--theme); font-size: 24rpx; }
.trace { display: flex; padding: 12rpx 0 0; color: #8b877d; font-size: 26rpx; }
.trace.first { color: #303133; }
.dot { width: 14rpx; height: 14rpx; border-radius: 50%; background: #ded6c8; margin: 12rpx 18rpx 0 6rpx; flex: none; }
.trace.first .dot { background: var(--theme); }
.tbody { flex: 1; min-width: 0; }
.track-btn { text-align: center; color: var(--theme); padding: 16rpx 0 4rpx; }
.item { display: flex; margin-bottom: 16rpx; }
.thumb { width: 120rpx; height: 120rpx; border-radius: 12rpx; background: #efe8dc; flex: none; }
.body { flex: 1; min-width: 0; margin: 0 20rpx; }
.right { text-align: right; flex: none; }
.rv-btn { display: inline-block; margin-top: 10rpx; padding: 4rpx 24rpx; border-radius: 28rpx; border: 2rpx solid var(--theme); color: var(--theme); font-size: 24rpx; }
.rv-done { margin-top: 10rpx; }
.kv { display: flex; justify-content: space-between; padding: 8rpx 0; }
.total { border-top: 2rpx solid var(--line); margin-top: 8rpx; padding-top: 16rpx; font-weight: 600; }
.bar { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; display: flex; justify-content: flex-end; gap: 16rpx; padding: 16rpx 24rpx; box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.05); }
</style>
