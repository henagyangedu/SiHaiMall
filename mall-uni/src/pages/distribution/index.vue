<script setup>
import { ref, reactive } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getDistSummary, applyDistributor } from '@/api'
import { money } from '@/utils/app'
import { usePageTitle, toast, nav } from '@/utils/page'

const { t } = useI18n()
usePageTitle('dist.title')

// 分销员状态：null 未申请，0 待审核，1 正常，2 已拒绝，3 已停用。佣金金额都是基准币种
const s = ref(null)
const form = reactive({ real_name: '', remark: '' })
const applying = ref(false)
const base = () => state.config.base_currency

async function load() {
  s.value = await getDistSummary()
}
async function apply() {
  if (applying.value) return
  if (!form.real_name.trim()) return toast(t('dist.needName'))
  applying.value = true
  try {
    await applyDistributor({ real_name: form.real_name.trim(), remark: form.remark.trim() })
    load()
  } finally {
    applying.value = false
  }
}
function copy() {
  uni.setClipboardData({ data: s.value.invite_code, success: () => toast(t('dist.copied')) })
}

onShow(load)
</script>

<template>
  <view v-if="s" class="page">
    <view v-if="!s.enabled && s.status === null" class="empty">{{ t('dist.closed') }}</view>

    <!-- 还没申请，或被拒绝后重新申请 -->
    <view v-else-if="s.status === null || s.status === 2" class="card">
      <view class="strong">{{ t('dist.applyTitle') }}</view>
      <view class="sub">{{ t('dist.applyTip') }}</view>
      <view class="sub">{{ t('dist.rate', { a: Number(s.rate_1) }) }}<text v-if="s.level === 2">{{ t('dist.rate2', { b: Number(s.rate_2) }) }}</text></view>
      <view v-if="s.status === 2" class="warn">{{ t('dist.rejected') }}<text v-if="s.reject_reason">：{{ s.reject_reason }}</text></view>
      <input v-model="form.real_name" class="input" maxlength="60" :placeholder="t('dist.realName')" />
      <input v-model="form.remark" class="input" maxlength="255" :placeholder="t('dist.remark')" />
      <view class="btn" :class="{ disabled: applying }" style="margin-top: 30rpx" @click="apply">{{ t(s.status === 2 ? 'dist.reapply' : 'dist.submitApply') }}</view>
    </view>

    <view v-else-if="s.status === 0" class="empty">{{ t('dist.pending') }}</view>

    <template v-else>
      <view v-if="s.status === 3" class="banner">{{ t('dist.disabled') }}</view>
      <view class="head">
        <view class="label">{{ t('dist.available') }}</view>
        <view class="big">{{ money(s.available_amount, base()) }}</view>
        <view class="nums">
          <view><view class="n">{{ money(s.pending_amount, base()) }}</view><view class="l">{{ t('dist.pendingAmount') }}</view></view>
          <view><view class="n">{{ money(s.frozen_amount, base()) }}</view><view class="l">{{ t('dist.frozen') }}</view></view>
          <view><view class="n">{{ money(s.withdrawn_amount, base()) }}</view><view class="l">{{ t('dist.withdrawn') }}</view></view>
        </view>
      </view>

      <view class="card">
        <view class="invite">
          <view><view class="sub">{{ t('dist.inviteCode') }}</view><view class="code">{{ s.invite_code }}</view></view>
          <view class="btn small plain" @click="copy">{{ t('dist.copy') }}</view>
        </view>
        <view class="sub">{{ t('dist.inviteTip') }}</view>
        <view class="sub">{{ t('dist.rate', { a: Number(s.rate_1) }) }}<text v-if="s.level === 2">{{ t('dist.rate2', { b: Number(s.rate_2) }) }}</text></view>
      </view>

      <view class="card list">
        <view class="cell" @click="nav('/pages/distribution/records?type=commission')"><text>{{ t('dist.commissions') }}</text><text class="sub">›</text></view>
        <view class="cell" @click="nav('/pages/distribution/records?type=team')">
          <text>{{ t('dist.team') }}</text>
          <text class="sub">{{ t('dist.team1') }} {{ s.team_level1 }}<text v-if="s.level === 2">　{{ t('dist.team2') }} {{ s.team_level2 }}</text> ›</text>
        </view>
        <view class="cell" @click="nav('/pages/distribution/withdraw')"><text>{{ t('dist.withdraw') }}</text><text class="sub">›</text></view>
      </view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
.strong { font-weight: 600; font-size: 32rpx; margin-bottom: 8rpx; }
.warn { color: var(--theme); margin-top: 12rpx; }
.input { height: 92rpx; border-bottom: 2rpx solid var(--line); }
.banner { background: #fdf6ec; color: #e6a23c; padding: 20rpx 32rpx; font-size: 26rpx; }
.head { background: var(--theme); color: #fff; padding: 48rpx 40rpx 36rpx; }
.label { opacity: 0.9; font-size: 26rpx; }
.big { font-size: 64rpx; font-weight: 600; margin: 8rpx 0 28rpx; }
.nums { display: flex; }
.nums > view { flex: 1; }
.n { font-size: 30rpx; font-weight: 600; }
.l { font-size: 22rpx; opacity: 0.85; }
.invite { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12rpx; }
.code { font-size: 44rpx; font-weight: 600; letter-spacing: 6rpx; }
.list { padding: 0 24rpx; }
.cell { display: flex; justify-content: space-between; align-items: center; height: 100rpx; border-bottom: 2rpx solid var(--line); }
.cell:last-child { border-bottom: none; }
</style>
