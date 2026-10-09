<script setup>
import { ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getOrder, cancelOrder, receiveOrder, getPayChannels, startPay, confirmPay } from '@/api'
import { money } from '@/utils/app'
import { usePageTitle, confirm, toast, nav } from '@/utils/page'

const { t } = useI18n()
usePageTitle('order.detail')

const orderNo = ref('')
const order = ref(null)
let autoPay = false

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
      const r = await startPay(orderNo.value, { channel: channels[tapIndex].channel, return_url: returnUrl })
      uni.setStorageSync(tradeKey(), r.trade_no)
      // #ifdef H5
      if (r.redirect_url) window.location.href = r.redirect_url
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
})
onShow(async () => {
  await load()
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
      <view v-for="(s, i) in order.shippings" :key="i" class="kv"><text>{{ s.express_name }}</text><text selectable>{{ s.express_no }}</text></view>
    </view>

    <view class="card">
      <view v-for="(i, k) in order.items" :key="k" class="item">
        <image :src="i.image" mode="aspectFill" class="thumb" />
        <view class="body">
          <view class="ellipsis-2">{{ i.title }}</view>
          <view class="sub ellipsis">{{ i.spec_text }}</view>
        </view>
        <view class="right"><view>{{ money(i.price, order.currency) }}</view><view class="sub">× {{ i.qty }}</view></view>
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
.item { display: flex; margin-bottom: 16rpx; }
.thumb { width: 120rpx; height: 120rpx; border-radius: 12rpx; background: #f0f1f3; flex: none; }
.body { flex: 1; min-width: 0; margin: 0 20rpx; }
.right { text-align: right; flex: none; }
.kv { display: flex; justify-content: space-between; padding: 8rpx 0; }
.total { border-top: 2rpx solid var(--line); margin-top: 8rpx; padding-top: 16rpx; font-weight: 600; }
.bar { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; display: flex; justify-content: flex-end; gap: 16rpx; padding: 16rpx 24rpx; box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.05); }
</style>
