<script setup>
import { ref, reactive } from 'vue'
import { onShow, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getMessages, readMessage, readAllMessages, deleteMessage } from '@/api'
import { usePageTitle, nav } from '@/utils/page'

// 消息中心（站内信）：支付成功、发货、退款、到货提醒等通知，以及商家在后台群发的消息。点一条就标为已读；有关联的订单 / 售后单 / 商品时可以点“查看详情”过去
const { t } = useI18n()
usePageTitle('msg.title')

const list = ref([])
const page = reactive({ no: 1, total: 0, loading: false, loaded: false })

async function load(reset = false) {
  if (page.loading) return
  if (reset) { page.no = 1; list.value = [] }
  page.loading = true
  try {
    const d = await getMessages({ page: page.no, size: 10 })
    list.value = reset ? d.list : list.value.concat(d.list)
    page.total = d.total
    state.unread = d.unread
    page.no += 1
  } finally {
    page.loading = false
    page.loaded = true
  }
}
async function markRead(m) {
  if (m.is_read) return
  m.is_read = true
  state.unread = Math.max(0, state.unread - 1)
  await readMessage(m.id)
}
async function readAll() {
  await readAllMessages()
  list.value.forEach((m) => { m.is_read = true })
  state.unread = 0
}
async function remove(m) {
  await deleteMessage(m.id)
  if (!m.is_read) state.unread = Math.max(0, state.unread - 1)
  list.value = list.value.filter((x) => x.id !== m.id)
  page.total -= 1
}
// 消息关联的页面；没有关联的返回空串
function target(m) {
  if (m.link_type === 'order' && m.link_value) return `/pages/order/detail?order_no=${m.link_value}`
  if (m.link_type === 'refund' && m.link_value) return `/pages/refund/detail?refund_no=${m.link_value}`
  if (m.link_type === 'goods' && m.link_value) return `/pages/goods/detail?id=${m.link_value}`
  if (m.link_type === 'withdraw') return '/pages/distribution/index'
  // 后台群发的消息还可以跳到文章、领券中心
  if (m.link_type === 'article' && m.link_value) return `/pages/article/detail?id=${m.link_value}`
  if (m.link_type === 'coupon') return '/pages/coupon/index'
  return ''
}
async function open(m) {
  await markRead(m).catch(() => {})
  nav(target(m))
}

onShow(() => load(true))
onPullDownRefresh(() => load(true).finally(() => uni.stopPullDownRefresh()))
onReachBottom(() => { if (list.value.length < page.total) load() })
</script>

<template>
  <view>
    <view v-if="state.unread" class="bar">
      <text class="sub">{{ t('msg.unreadCount', { n: state.unread }) }}</text>
      <text class="read-all" @click="readAll">{{ t('msg.readAll') }}</text>
    </view>
    <view v-for="m in list" :key="m.id" class="card msg" :class="{ unread: !m.is_read }" @click="markRead(m)">
      <view class="head">
        <view class="dot" />
        <text class="title">{{ m.title }}</text>
      </view>
      <text class="content">{{ m.content }}</text>
      <view class="foot">
        <text class="sub time">{{ m.created_at }}</text>
        <text v-if="target(m)" class="view" @click.stop="open(m)">{{ t('msg.view') }}</text>
        <text class="remove" @click.stop="remove(m)">{{ t('msg.remove') }}</text>
      </view>
    </view>
    <view v-if="page.loaded && !list.length" class="empty">{{ t('msg.empty') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.bar { display: flex; justify-content: space-between; align-items: center; padding: 20rpx 32rpx 0; font-size: 26rpx; }
.read-all { color: var(--theme); padding: 8rpx 0 8rpx 24rpx; }
.head { display: flex; align-items: flex-start; }
.dot { display: none; flex: none; width: 14rpx; height: 14rpx; margin: 14rpx 14rpx 0 0; border-radius: 50%; background: var(--theme); }
.unread .dot { display: block; }
.title { flex: 1; min-width: 0; font-size: 28rpx; line-height: 1.5; color: var(--sub); }
.unread .title { color: var(--text); font-weight: 600; }
.content { display: block; margin-top: 12rpx; font-size: 26rpx; line-height: 1.7; color: var(--sub); white-space: pre-wrap; word-break: break-all; }
.foot { margin-top: 16rpx; display: flex; align-items: center; font-size: 24rpx; }
.time { flex: 1; }
.view { color: var(--theme); padding: 8rpx 0 8rpx 32rpx; }
.remove { color: var(--sub); padding: 8rpx 0 8rpx 32rpx; }
</style>
