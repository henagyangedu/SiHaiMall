<script setup>
import { ref, reactive } from 'vue'
import { onShow, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getFavorites, removeFavorite } from '@/api'
import { usePageTitle, nav } from '@/utils/page'

const { t } = useI18n()
usePageTitle('fav.title')

const list = ref([])
const page = reactive({ no: 1, total: 0, loading: false, loaded: false })

async function load(reset = false) {
  if (page.loading) return
  if (reset) { page.no = 1; list.value = [] }
  page.loading = true
  try {
    const d = await getFavorites({ page: page.no, size: 10 })
    list.value = reset ? d.list : list.value.concat(d.list)
    page.total = d.total
    page.no += 1
  } finally {
    page.loading = false
    page.loaded = true
  }
}
async function remove(g) {
  await removeFavorite(g.id)
  list.value = list.value.filter((x) => x.id !== g.id)
  page.total -= 1
}
// 失效的商品（下架或删除了）点了没有反应
const open = (g) => { if (!g.invalid) nav(`/pages/goods/detail?id=${g.id}`) }

onShow(() => load(true))
onPullDownRefresh(() => load(true).finally(() => uni.stopPullDownRefresh()))
onReachBottom(() => { if (list.value.length < page.total) load() })
</script>

<template>
  <view>
    <view v-for="g in list" :key="g.id" class="card row" :class="{ invalid: g.invalid }" @click="open(g)">
      <view class="cover">
        <image v-if="g.cover" :src="g.cover" mode="aspectFill" class="img" lazy-load />
        <view v-if="g.invalid" class="mask">{{ t('fav.invalid') }}</view>
      </view>
      <view class="body">
        <view class="title ellipsis-2">{{ g.title }}</view>
        <view class="foot">
          <text v-if="!g.invalid" class="price">{{ g.symbol }}{{ g.price }}</text>
          <text v-else class="sub">{{ t('fav.invalidTip') }}</text>
          <text class="remove" @click.stop="remove(g)">{{ t('fav.remove') }}</text>
        </view>
      </view>
    </view>
    <view v-if="page.loaded && !list.length" class="empty">{{ t('fav.empty') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.row { display: flex; gap: 20rpx; }
.cover { position: relative; width: 180rpx; height: 180rpx; border-radius: 16rpx; overflow: hidden; background: var(--cream-deep); flex: none; }
.img { width: 100%; height: 100%; display: block; }
.mask { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(41, 43, 39, 0.5); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 24rpx; }
.body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.title { font-size: 28rpx; line-height: 1.4; }
.invalid .title { color: var(--sub); }
.foot { margin-top: auto; display: flex; justify-content: space-between; align-items: center; }
.price { font-size: 32rpx; }
.remove { color: var(--sub); font-size: 24rpx; padding: 8rpx 0 8rpx 24rpx; }
</style>
