<script setup>
import { ref, reactive } from 'vue'
import { onLoad, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getCommissions, getTeam, getDistSummary } from '@/api'
import { money } from '@/utils/app'
import { usePageTitle } from '@/utils/page'

const { t } = useI18n()
// 佣金明细和我的团队共用一个列表页，type = commission | team
const type = ref('commission')
usePageTitle(() => t(type.value === 'team' ? 'dist.team' : 'dist.commissions'))

const level = ref(1)
const twoLevels = ref(false)
const list = ref([])
const page = reactive({ no: 1, total: 0, loading: false })
const base = () => state.config.base_currency

async function load(reset = false) {
  if (page.loading) return
  if (reset) { page.no = 1; list.value = [] }
  page.loading = true
  try {
    const d = type.value === 'team'
      ? await getTeam({ level: level.value, page: page.no, size: 20 })
      : await getCommissions({ page: page.no, size: 20 })
    list.value = reset ? d.list : list.value.concat(d.list)
    page.total = d.total
    page.no += 1
  } finally {
    page.loading = false
  }
}
function pickLevel(n) {
  level.value = n
  load(true)
}

onLoad(async (q) => {
  type.value = q.type === 'team' ? 'team' : 'commission'
  if (type.value === 'team') {
    try { twoLevels.value = (await getDistSummary()).level === 2 } catch (e) { /* 忽略 */ }
  }
  load(true)
})
onReachBottom(() => { if (list.value.length < page.total) load() })
</script>

<template>
  <view>
    <view v-if="type === 'team' && twoLevels" class="tabs">
      <view class="tab" :class="{ on: level === 1 }" @click="pickLevel(1)">{{ t('dist.team1') }}</view>
      <view class="tab" :class="{ on: level === 2 }" @click="pickLevel(2)">{{ t('dist.team2') }}</view>
    </view>

    <template v-if="type === 'team'">
      <view v-for="(m, i) in list" :key="i" class="card row">
        <view class="avatar">{{ m.nickname.slice(0, 1) }}</view>
        <view class="body"><view>{{ m.nickname }}</view><view class="sub">{{ t('dist.joinedAt') }} {{ m.joined_at }}</view></view>
      </view>
    </template>
    <template v-else>
      <view v-for="(c, i) in list" :key="i" class="card">
        <view class="head"><text class="sub">{{ c.order_no }}</text><text class="status">{{ t(`commissionStatus.${c.status}`) }}</text></view>
        <view class="line">
          <view>
            <view>{{ t('dist.buyer') }} {{ c.buyer }}　{{ t('dist.level', { n: c.level }) }}</view>
            <view class="sub">{{ t('dist.base') }} {{ money(c.base_amount, base()) }} × {{ Number(c.rate) }}%</view>
            <view class="sub">{{ c.created_at }}</view>
          </view>
          <view class="price big">+{{ money(c.amount, base()) }}</view>
        </view>
      </view>
    </template>
    <view class="sub end">{{ page.loading ? t('common.loading') : (list.length ? t('common.noMore') : t('common.empty')) }}</view>
  </view>
</template>

<style lang="scss" scoped>
.tabs { display: flex; background: #fff; }
.tab { flex: 1; text-align: center; padding: 24rpx 0; color: #606266; border-bottom: 4rpx solid transparent; }
.tab.on { color: var(--theme); border-bottom-color: var(--theme); font-weight: 600; }
.row { display: flex; align-items: center; }
.avatar { width: 80rpx; height: 80rpx; border-radius: 50%; background: #f0f1f3; text-align: center; line-height: 80rpx; margin-right: 20rpx; flex: none; }
.body { flex: 1; }
.head { display: flex; justify-content: space-between; margin-bottom: 12rpx; }
.status { color: var(--theme); }
.line { display: flex; justify-content: space-between; align-items: center; }
.big { font-size: 34rpx; }
.end { text-align: center; padding: 30rpx 0 40rpx; }
</style>
