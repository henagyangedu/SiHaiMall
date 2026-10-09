<script setup>
import { ref, reactive } from 'vue'
import { onLoad, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getGoodsReviews } from '@/api'
import { usePageTitle } from '@/utils/page'
import ReviewItem from '@/components/ReviewItem.vue'

const { t } = useI18n()
usePageTitle('review.title')

const goodsId = ref(0)
const summary = ref(null)
const list = ref([])
const level = ref('all')
const page = reactive({ no: 1, total: 0, loading: false })

// 筛选：全部 / 好评 / 中评 / 差评 / 有图，括号里是各自的条数
const LEVELS = [['all', 'count'], ['good', 'good'], ['mid', 'mid'], ['bad', 'bad'], ['image', 'with_images']]

async function load(reset = false) {
  if (page.loading) return
  if (reset) { page.no = 1; list.value = [] }
  page.loading = true
  try {
    const d = await getGoodsReviews(goodsId.value, { level: level.value, page: page.no, size: 10 })
    summary.value = d.summary
    list.value = reset ? d.list : list.value.concat(d.list)
    page.total = d.total
    page.no += 1
  } finally {
    page.loading = false
  }
}
function pick(l) {
  if (level.value === l) return
  level.value = l
  load(true)
}

onLoad((q) => {
  goodsId.value = Number(q.goods_id) || 0
  load(true)
})
onReachBottom(() => { if (list.value.length < page.total) load() })
</script>

<template>
  <view v-if="summary">
    <view class="card head">
      <view class="rate"><text class="num">{{ summary.good_rate }}%</text>{{ t('review.goodRate') }}</view>
      <view class="levels">
        <view v-for="[l, key] in LEVELS" :key="l" class="level" :class="{ on: level === l }" @click="pick(l)">{{ t(`review.level.${l}`) }} {{ summary[key] }}</view>
      </view>
    </view>
    <view v-if="list.length" class="card">
      <ReviewItem v-for="r in list" :key="r.id" :review="r" />
    </view>
    <view v-else-if="!page.loading" class="empty">{{ t('review.empty') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.rate { color: var(--sub); font-size: 24rpx; margin-bottom: 20rpx; }
.num { color: var(--theme); font-size: 44rpx; font-weight: 600; margin-right: 12rpx; }
.levels { display: flex; flex-wrap: wrap; gap: 16rpx; }
.level { padding: 8rpx 24rpx; border-radius: 28rpx; background: #f5f6f8; font-size: 24rpx; }
.level.on { background: #fdecec; color: var(--theme); }
</style>
