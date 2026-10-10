<script setup>
import { ref, reactive, computed } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getDistSummary, getWithdraws, applyWithdraw } from '@/api'
import { money } from '@/utils/app'
import { usePageTitle, toast } from '@/utils/page'

const { t } = useI18n()
usePageTitle('dist.withdraw')

const METHODS = [
  { key: 'bank', label: 'dist.methodBank' }, { key: 'paypal', label: 'dist.methodPaypal' }, { key: 'alipay', label: 'dist.methodAlipay' },
  { key: 'wechat', label: 'dist.methodWechat' }, { key: 'other', label: 'dist.methodOther' },
]
const s = ref(null)
const list = ref([])
const form = reactive({ amount: '', method: 'bank', name: '', no: '', bank: '' })
const submitting = ref(false)
const base = () => state.config.base_currency
const methodIndex = computed(() => METHODS.findIndex((m) => m.key === form.method))

async function load() {
  s.value = await getDistSummary()
  list.value = (await getWithdraws({ size: 20 })).list
}
async function submit() {
  if (submitting.value) return
  const amount = Number(form.amount)
  if (!amount || amount <= 0) return toast(t('dist.needAmount'))
  if (!form.name.trim() || !form.no.trim()) return toast(t('dist.needAccount'))
  if (form.method === 'bank' && !form.bank.trim()) return toast(t('dist.needBank'))
  submitting.value = true
  try {
    const account = { name: form.name.trim(), no: form.no.trim(), ...(form.bank.trim() ? { bank: form.bank.trim() } : {}) }
    await applyWithdraw({ amount: form.amount, method: form.method, account })
    toast(t('dist.withdrawSent'))
    form.amount = ''
    load()
  } finally {
    submitting.value = false
  }
}

onShow(load)
</script>

<template>
  <view v-if="s" class="page">
    <view class="card">
      <view class="strong">{{ t('dist.withdrawApply') }}</view>
      <view class="sub">{{ t('dist.minTip', { amount: money(s.min_withdraw, base()), available: money(s.available_amount, base()) }) }}</view>
      <view class="row"><text class="label">{{ t('dist.amount') }}</text><input v-model="form.amount" class="input" type="digit" maxlength="12" /></view>
      <view class="row">
        <text class="label">{{ t('dist.method') }}</text>
        <picker class="input" :range="METHODS.map((m) => t(m.label))" :value="methodIndex" @change="(e) => form.method = METHODS[Number(e.detail.value)].key">
          <view>{{ t(METHODS[methodIndex].label) }} ›</view>
        </picker>
      </view>
      <view class="row"><text class="label">{{ t('dist.accountName') }}</text><input v-model="form.name" class="input" maxlength="100" /></view>
      <view class="row"><text class="label">{{ t('dist.accountNo') }}</text><input v-model="form.no" class="input" maxlength="100" /></view>
      <view v-if="form.method === 'bank'" class="row"><text class="label">{{ t('dist.bank') }}</text><input v-model="form.bank" class="input" maxlength="100" /></view>
      <view class="btn" :class="{ disabled: submitting }" style="margin-top: 30rpx" @click="submit">{{ t('dist.submitWithdraw') }}</view>
    </view>

    <view v-if="list.length" class="section">{{ t('dist.history') }}</view>
    <view v-for="w in list" :key="w.withdraw_no" class="card">
      <view class="head"><text class="sub">{{ w.withdraw_no }}</text><text class="status">{{ t(`withdrawStatus.${w.status}`) }}</text></view>
      <view class="line"><text>{{ w.created_at }}</text><text class="price">{{ money(w.amount, base()) }}</text></view>
      <view v-if="w.reject_reason" class="sub">{{ w.reject_reason }}</view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding-bottom: 60rpx; }
.strong { font-weight: 600; font-size: 32rpx; }
.row { display: flex; align-items: center; min-height: 92rpx; border-bottom: 2rpx solid var(--line); }
.label { width: 200rpx; flex: none; color: #6a665e; }
.input { flex: 1; min-width: 0; }
.section { padding: 20rpx 32rpx 0; color: #6a665e; }
.head { display: flex; justify-content: space-between; margin-bottom: 8rpx; }
.status { color: var(--theme); }
.line { display: flex; justify-content: space-between; }
</style>
