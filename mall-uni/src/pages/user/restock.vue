<script setup>
import { ref, reactive } from 'vue'
import { onShow, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getStockSubscribes, removeStockSubscribe } from '@/api'
import { usePageTitle, nav } from '@/utils/page'

// 我的到货提醒：订阅过的缺货规格。等待到货 / 已到货（已经通知过）/ 已下架
const { t } = useI18n()
usePageTitle('restock.title')

const list = ref([])
const page = reactive({ no: 1, total: 0, loading: false, loaded: false })

async function load(reset = false) {
  if (page.loading) return
  if (reset) { page.no = 1; list.value = [] }
  page.loading = true
  try {
    const d = await getStockSubscribes({ page: page.no, size: 10 })
    list.value = reset ? d.list : list.value.concat(d.list)
    page.total = d.total
    page.no += 1
  } finally {
    page.loading = false
    page.loaded = true
  }
}
async function remove(r) {
  await removeStockSubscribe(r.sku_id)
  list.value = list.value.filter((x) => x.sku_id !== r.sku_id)
  page.total -= 1
}
const stateKey = (r) => (r.invalid ? 'invalid' : (r.in_stock ? 'arrived' : 'waiting'))
// 已下架的商品点了没有反应
const open = (r) => { if (!r.invalid) nav(`/pages/goods/detail?id=${r.goods_id}`) }

onShow(() => load(true))
onPullDownRefresh(() => load(true).finally(() => uni.stopPullDownRefresh()))
onReachBottom(() => { if (list.value.length < page.total) load() })
</script>

<template>
  <view>
    <view v-for="r in list" :key="r.sku_id" class="card row" :class="{ invalid: r.invalid }" @click="open(r)">
      <view class="cover"><image v-if="r.cover" :src="r.cover" mode="aspectFill" class="img" lazy-load /></view>
      <view class="body">
        <view class="title ellipsis-2">{{ r.title }}</view>
        <view v-if="r.spec_text" class="sub spec ellipsis">{{ r.spec_text }}</view>
        <view class="foot">
          <text class="state" :class="stateKey(r)">{{ t(`restock.${stateKey(r)}`) }}</text>
          <text v-if="r.in_stock" class="buy">{{ t('restock.buy') }}</text>
          <text class="remove" @click.stop="remove(r)">{{ t(r.status === 'waiting' && !r.invalid ? 'restock.cancel' : 'fav.remove') }}</text>
        </view>
      </view>
    </view>
    <view v-if="page.loaded && !list.length" class="empty">{{ t('restock.empty') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.row { display: flex; gap: 20rpx; }
.cover { width: 180rpx; height: 180rpx; border-radius: 16rpx; overflow: hidden; background: var(--cream-deep); flex: none; }
.img { width: 100%; height: 100%; display: block; }
.body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.title { font-size: 28rpx; line-height: 1.4; }
.invalid .title { color: var(--sub); }
.spec { margin-top: 6rpx; font-size: 24rpx; }
.foot { margin-top: auto; display: flex; align-items: center; }
.state { font-size: 24rpx; color: var(--sub); }
.state.arrived { color: var(--theme); font-weight: 600; }
.buy { margin-left: 20rpx; padding: 0 20rpx; height: 48rpx; line-height: 48rpx; border-radius: 24rpx; background: var(--theme); color: #fff; font-size: 24rpx; }
.remove { margin-left: auto; color: var(--sub); font-size: 24rpx; padding: 8rpx 0 8rpx 24rpx; }
</style>
