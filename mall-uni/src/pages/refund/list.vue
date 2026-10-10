<script setup>
import { ref, reactive } from 'vue'
import { onShow, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getRefunds } from '@/api'
import { money } from '@/utils/app'
import { usePageTitle, nav } from '@/utils/page'

const { t } = useI18n()
usePageTitle('refund.list')

const list = ref([])
const page = reactive({ no: 1, total: 0, loading: false })

async function load(reset = false) {
  if (page.loading) return
  if (reset) { page.no = 1; list.value = [] }
  page.loading = true
  try {
    const d = await getRefunds({ page: page.no, size: 10 })
    list.value = reset ? d.list : list.value.concat(d.list)
    page.total = d.total
    page.no += 1
  } finally {
    page.loading = false
  }
}

onShow(() => load(true))
onPullDownRefresh(() => load(true).finally(() => uni.stopPullDownRefresh()))
onReachBottom(() => { if (list.value.length < page.total) load() })
</script>

<template>
  <view>
    <view v-for="r in list" :key="r.refund_no" class="card" @click="nav(`/pages/refund/detail?refund_no=${r.refund_no}`)">
      <view class="head"><text class="sub">{{ r.refund_no }}</text><text class="status">{{ t(`refundStatus.${r.status}`) }}</text></view>
      <view v-for="(i, k) in r.items" :key="k" class="item">
        <image :src="i.image" mode="aspectFill" class="thumb" />
        <view class="body">
          <view class="ellipsis-2">{{ i.title }}</view>
          <view class="sub ellipsis">{{ i.spec_text }}　× {{ i.qty }}</view>
        </view>
      </view>
      <view class="foot">
        <text class="sub">{{ r.whole_order ? t('refund.whole') : t(`refundType.${r.type}`) }}　</text>{{ t('refund.amount') }} <text class="price">{{ money(r.refund_amount, r.currency) }}</text>
      </view>
    </view>
    <view class="sub end">{{ page.loading ? t('common.loading') : (list.length ? t('common.noMore') : t('common.empty')) }}</view>
  </view>
</template>

<style lang="scss" scoped>
.head { display: flex; justify-content: space-between; margin-bottom: 16rpx; }
.status { color: var(--theme); }
.item { display: flex; margin-bottom: 16rpx; }
.thumb { width: 120rpx; height: 120rpx; border-radius: 12rpx; background: #efe8dc; flex: none; }
.body { flex: 1; min-width: 0; margin-left: 20rpx; }
.foot { text-align: right; }
.end { text-align: center; padding: 30rpx 0 40rpx; }
</style>
