<script setup>
import { ref, reactive } from 'vue'
import { onLoad, onShow, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getCoupons, receiveCoupon, getMyCoupons } from '@/api'
import { couponText, couponValidText } from '@/utils/app'
import { needLogin } from '@/utils/request'
import { usePageTitle, toast } from '@/utils/page'

// 优惠券：一个页面四个页签——可领取（领券中心）、未使用、已使用、已过期（后三个是“我的优惠券”，要登录）
const { t } = useI18n()
usePageTitle('coupon.title')

const TABS = ['receivable', 'unused', 'used', 'expired']
const tab = ref('receivable')
const list = ref([])
const page = reactive({ no: 1, total: 0, loading: false, loaded: false })
const claiming = ref(0)

async function load(reset = false) {
  if (page.loading) return
  if (reset) { page.no = 1; list.value = []; page.loaded = false }
  page.loading = true
  try {
    if (tab.value === 'receivable') {
      list.value = await getCoupons()
      page.total = list.value.length
    } else {
      const d = await getMyCoupons({ status: tab.value, page: page.no, size: 10 })
      list.value = reset ? d.list : list.value.concat(d.list)
      page.total = d.total
      page.no += 1
    }
  } finally {
    page.loading = false
    page.loaded = true
  }
}
function pick(name) {
  if (name === tab.value) return
  // “我的优惠券”要登录；没登录时留在领券中心
  if (name !== 'receivable' && !state.token) return needLogin(() => {})
  tab.value = name
  load(true)
}
function claim(c) {
  needLogin(async () => {
    if (claiming.value || !c.can_receive) return
    claiming.value = c.coupon_id
    try {
      await receiveCoupon(c.coupon_id)
      toast(t('coupon.receiveOk'))
      await load(true)
    } finally {
      claiming.value = 0
    }
  })
}

onLoad((q) => { if (TABS.includes(q.tab) && (q.tab === 'receivable' || state.token)) tab.value = q.tab })
onShow(() => load(true))
onReachBottom(() => { if (tab.value !== 'receivable' && list.value.length < page.total) load() })
</script>

<template>
  <view>
    <view class="tabs">
      <view v-for="x in TABS" :key="x" class="tab" :class="{ on: tab === x }" @click="pick(x)">{{ t(`coupon.${x}`) }}</view>
    </view>

    <view v-for="c in list" :key="c.id || c.coupon_id" class="coupon" :class="{ dim: tab === 'used' || tab === 'expired' }">
      <view class="face">
        <view class="value">{{ couponText(c, true) }}</view>
        <view class="cond">{{ couponText(c, false, true) }}</view>
      </view>
      <view class="body">
        <view class="name ellipsis">{{ c.name }}</view>
        <view class="sub">{{ t(c.scope === 1 ? 'coupon.scopeAll' : 'coupon.scopePart') }}</view>
        <view class="sub">{{ couponValidText(c) }}</view>
      </view>
      <view v-if="tab === 'receivable'" class="act" :class="{ off: !c.can_receive }" @click="claim(c)">{{ t(c.can_receive ? 'coupon.receive' : 'coupon.received') }}</view>
    </view>
    <view v-if="page.loaded && !list.length" class="empty">{{ t('coupon.empty') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.tabs { display: flex; background: #fff; position: sticky; top: var(--window-top); z-index: 2; }
.tab { flex: 1; text-align: center; padding: 24rpx 0; color: #6a665e; font-size: 26rpx; border-bottom: 4rpx solid transparent; }
.tab.on { color: var(--theme); border-bottom-color: var(--theme); font-weight: 600; }
.coupon { display: flex; align-items: center; margin: 20rpx 24rpx 0; background: #fff; border-radius: 24rpx; overflow: hidden; }
.coupon.dim { opacity: 0.55; }
.face { width: 220rpx; flex: none; align-self: stretch; padding: 28rpx 12rpx; background: var(--theme); color: #fff; text-align: center; display: flex; flex-direction: column; justify-content: center; }
.value { font-size: 38rpx; font-weight: 700; line-height: 1.2; }
.cond { margin-top: 8rpx; font-size: 22rpx; opacity: 0.9; }
.body { flex: 1; min-width: 0; padding: 20rpx 24rpx; line-height: 1.6; }
.name { font-size: 28rpx; font-weight: 600; }
.act { flex: none; margin-right: 24rpx; padding: 0 28rpx; height: 56rpx; line-height: 56rpx; border-radius: 28rpx; background: var(--theme); color: #fff; font-size: 24rpx; }
.act.off { background: var(--cream-deep); color: var(--sub); }
</style>
