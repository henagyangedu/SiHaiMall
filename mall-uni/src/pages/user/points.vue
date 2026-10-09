<script setup>
import { ref, reactive } from 'vue'
import { onShow, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getProfile, getPointLogs } from '@/api'
import { usePageTitle } from '@/utils/page'

const { t, te } = useI18n()
usePageTitle('points.title')

const list = ref([])
const page = reactive({ no: 1, total: 0, loading: false })

// 流水类型由前端按类型码翻译；遇到没登记的类型显示“其他”
const typeText = (type) => (te(`pointType.${type}`) ? t(`pointType.${type}`) : t('points.other'))

async function load(reset = false) {
  if (page.loading) return
  if (reset) { page.no = 1; list.value = [] }
  page.loading = true
  try {
    const d = await getPointLogs({ page: page.no, size: 20 })
    list.value = reset ? d.list : list.value.concat(d.list)
    page.total = d.total
    page.no += 1
  } finally {
    page.loading = false
  }
}
async function refresh() {
  try { state.member = await getProfile() } catch (e) { /* 令牌失效时请求层已清掉登录态 */ }
  await load(true)
}

onShow(refresh)
onPullDownRefresh(() => refresh().finally(() => uni.stopPullDownRefresh()))
onReachBottom(() => { if (list.value.length < page.total) load() })
</script>

<template>
  <view>
    <view class="head">
      <view class="label">{{ t('points.balance') }}</view>
      <view class="num">{{ state.member?.points ?? 0 }}</view>
    </view>

    <view v-if="list.length" class="card rows">
      <view v-for="r in list" :key="r.id" class="row">
        <view class="body">
          <view>{{ typeText(r.type) }}</view>
          <view class="sub">{{ r.created_at }}<text v-if="r.ref_no">　{{ r.ref_no }}</text></view>
        </view>
        <view class="right">
          <view class="chg" :class="{ plus: r.change_points > 0 }">{{ r.change_points > 0 ? '+' : '' }}{{ r.change_points }}</view>
          <view class="sub">{{ t('points.after', { n: r.after_points }) }}</view>
        </view>
      </view>
    </view>
    <view class="sub end">{{ page.loading ? t('common.loading') : (list.length ? t('common.noMore') : t('common.empty')) }}</view>
  </view>
</template>

<style lang="scss" scoped>
.head { background: var(--theme); color: #fff; padding: 50rpx 40rpx; }
.label { font-size: 26rpx; opacity: 0.9; }
.num { font-size: 64rpx; font-weight: 600; margin-top: 8rpx; }
.rows { padding: 0 24rpx; }
.row { display: flex; justify-content: space-between; align-items: center; padding: 24rpx 0; border-bottom: 2rpx solid var(--line); }
.row:last-child { border-bottom: none; }
.body { flex: 1; min-width: 0; }
.right { text-align: right; flex: none; margin-left: 20rpx; }
.chg { font-size: 32rpx; font-weight: 600; }
.chg.plus { color: var(--theme); }
.end { text-align: center; padding: 30rpx 0 40rpx; }
</style>
