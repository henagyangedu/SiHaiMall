<script setup>
import { ref, reactive } from 'vue'
import { onLoad, onShow, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getOrders, cancelOrder, receiveOrder } from '@/api'
import { money } from '@/utils/app'
import { usePageTitle, confirm, toast, nav } from '@/utils/page'

const { t } = useI18n()
usePageTitle('order.list')

// 页签与后端的 tab 参数一致
const TABS = [
  { key: 'all', label: 'order.tabAll' }, { key: 'unpaid', label: 'order.tabUnpaid' }, { key: 'to_ship', label: 'order.tabToShip' },
  { key: 'shipped', label: 'order.tabShipped' }, { key: 'finished', label: 'order.tabFinished' },
]
const tab = ref('all')
const list = ref([])
const page = reactive({ no: 1, total: 0, loading: false })

async function load(reset = false) {
  if (page.loading) return
  if (reset) { page.no = 1; list.value = [] }
  page.loading = true
  try {
    const d = await getOrders({ tab: tab.value, page: page.no, size: 10 })
    list.value = reset ? d.list : list.value.concat(d.list)
    page.total = d.total
    page.no += 1
  } finally {
    page.loading = false
  }
}
function pick(key) {
  tab.value = key
  load(true)
}
const open = (o) => nav(`/pages/order/detail?order_no=${o.order_no}`)
const qty = (o) => o.items.reduce((s, i) => s + i.qty, 0)

async function onCancel(o) {
  if (!(await confirm(t('order.cancelConfirm')))) return
  await cancelOrder(o.order_no)
  toast(t('order.cancelled'))
  load(true)
}
async function onReceive(o) {
  if (!(await confirm(t('order.receiveConfirm')))) return
  await receiveOrder(o.order_no)
  toast(t('order.received'))
  load(true)
}

onLoad((q) => { if (q.tab) tab.value = q.tab })
// 从详情页返回时刷新（可能已支付、已取消）
onShow(() => load(true))
onPullDownRefresh(() => load(true).finally(() => uni.stopPullDownRefresh()))
onReachBottom(() => { if (list.value.length < page.total) load() })
</script>

<template>
  <view>
    <view class="tabs">
      <view v-for="x in TABS" :key="x.key" class="tab" :class="{ on: tab === x.key }" @click="pick(x.key)">{{ t(x.label) }}</view>
    </view>

    <view v-for="o in list" :key="o.order_no" class="card" @click="open(o)">
      <view class="head"><text class="sub">{{ o.order_no }}</text><text class="status">{{ o.status_text }}</text></view>
      <view v-for="(i, k) in o.items" :key="k" class="item">
        <image :src="i.image" mode="aspectFill" class="thumb" />
        <view class="body">
          <view class="ellipsis-2">{{ i.title }}</view>
          <view class="sub ellipsis">{{ i.spec_text }}</view>
        </view>
        <view class="right"><view>{{ money(i.price, o.currency) }}</view><view class="sub">× {{ i.qty }}</view></view>
      </view>
      <view class="foot">
        <text class="sub">{{ t('order.count', { n: qty(o) }) }}　</text>{{ t('order.payAmount') }} <text class="price">{{ money(o.pay_amount, o.currency) }}</text>
      </view>
      <view v-if="o.status === 0 || o.status === 2" class="actions" @click.stop>
        <template v-if="o.status === 0">
          <view class="btn small gray" @click="onCancel(o)">{{ t('order.cancel') }}</view>
          <view class="btn small" @click="nav(`/pages/order/detail?order_no=${o.order_no}&pay=1`)">{{ t('order.payNow') }}</view>
        </template>
        <view v-else class="btn small" @click="onReceive(o)">{{ t('order.receive') }}</view>
      </view>
    </view>
    <view class="sub end">{{ page.loading ? t('common.loading') : (list.length ? t('common.noMore') : t('common.empty')) }}</view>
  </view>
</template>

<style lang="scss" scoped>
.tabs { display: flex; background: #fff; position: sticky; top: var(--window-top); z-index: 2; }
.tab { flex: 1; text-align: center; padding: 24rpx 0; color: #606266; font-size: 26rpx; border-bottom: 4rpx solid transparent; }
.tab.on { color: var(--theme); border-bottom-color: var(--theme); font-weight: 600; }
.head { display: flex; justify-content: space-between; margin-bottom: 16rpx; }
.status { color: var(--theme); }
.item { display: flex; margin-bottom: 16rpx; }
.thumb { width: 120rpx; height: 120rpx; border-radius: 12rpx; background: #f0f1f3; flex: none; }
.body { flex: 1; min-width: 0; margin: 0 20rpx; }
.right { text-align: right; flex: none; }
.foot { text-align: right; }
.actions { display: flex; justify-content: flex-end; gap: 16rpx; margin-top: 20rpx; }
.end { text-align: center; padding: 30rpx 0 40rpx; }
</style>
