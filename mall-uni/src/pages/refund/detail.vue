<script setup>
import { ref, reactive } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getRefund, cancelRefund, submitRefundReturn } from '@/api'
import { money } from '@/utils/app'
import { usePageTitle, confirm, toast } from '@/utils/page'

const { t } = useI18n()
usePageTitle('refund.detail')

const refundNo = ref('')
const d = ref(null)
const ret = reactive({ express: '', express_no: '' })

async function load() {
  d.value = await getRefund(refundNo.value)
}
async function onCancel() {
  if (!(await confirm(t('refund.cancelConfirm')))) return
  await cancelRefund(refundNo.value)
  toast(t('refund.cancelled'))
  load()
}
async function onReturn() {
  if (!ret.express.trim() || !ret.express_no.trim()) return toast(t('refund.needExpress'))
  await submitRefundReturn(refundNo.value, { express: ret.express.trim(), express_no: ret.express_no.trim() })
  toast(t('refund.returnSent'))
  load()
}

onLoad((q) => {
  refundNo.value = q.refund_no || ''
  load()
})
</script>

<template>
  <view v-if="d" class="page">
    <view class="status">
      <view class="st">{{ t(`refundStatus.${d.status}`) }}</view>
      <view class="tip">{{ d.whole_order ? t('refund.whole') : t(`refundType.${d.type}`) }}　{{ money(d.refund_amount, d.currency) }}</view>
    </view>

    <view v-if="d.reject_reason" class="card">
      <view class="strong">{{ t('refund.rejectReason') }}</view>
      <view>{{ d.reject_reason }}</view>
    </view>

    <view v-if="d.can_submit_return" class="card">
      <view class="strong">{{ t('refund.returnTitle') }}</view>
      <view class="sub" style="margin-bottom: 12rpx">{{ t('refund.returnTip') }}</view>
      <input v-model="ret.express" class="input" maxlength="60" :placeholder="t('refund.express')" />
      <input v-model="ret.express_no" class="input" maxlength="60" :placeholder="t('refund.expressNo')" />
      <view class="btn" style="margin-top: 20rpx" @click="onReturn">{{ t('refund.submitReturn') }}</view>
    </view>
    <view v-else-if="d.return_express_no" class="card">
      <view class="kv"><text class="sub">{{ t('refund.returned') }}</text><text selectable>{{ d.return_express }} {{ d.return_express_no }}</text></view>
    </view>

    <view class="card">
      <view v-for="(i, k) in d.items" :key="k" class="item">
        <image :src="i.image" mode="aspectFill" class="thumb" />
        <view class="body">
          <view class="ellipsis-2">{{ i.title }}</view>
          <view class="sub ellipsis">{{ i.spec_text }}　× {{ i.qty }}</view>
        </view>
      </view>
      <view class="kv"><text class="sub">{{ t('refund.reason') }}</text><text class="val">{{ d.reason }}</text></view>
      <view class="kv"><text class="sub">{{ t('refund.amount') }}</text><text class="price">{{ money(d.refund_amount, d.currency) }}</text></view>
    </view>

    <view class="card">
      <view class="kv"><text class="sub">{{ t('refund.no') }}</text><text selectable>{{ d.refund_no }}</text></view>
      <view class="kv"><text class="sub">{{ t('refund.orderNo') }}</text><text selectable>{{ d.order_no }}</text></view>
      <view class="kv"><text class="sub">{{ t('refund.applyAt') }}</text><text>{{ d.created_at }}</text></view>
    </view>

    <view v-if="d.can_cancel" class="cancel" @click="onCancel">{{ t('refund.cancel') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding-bottom: 60rpx; }
.status { background: var(--theme); color: #fff; padding: 40rpx 32rpx; }
.st { font-size: 36rpx; font-weight: 600; }
.tip { font-size: 26rpx; opacity: 0.9; margin-top: 8rpx; }
.strong { font-weight: 600; margin-bottom: 8rpx; }
.input { height: 88rpx; border-bottom: 2rpx solid var(--line); }
.item { display: flex; margin-bottom: 16rpx; }
.thumb { width: 120rpx; height: 120rpx; border-radius: 12rpx; background: #f0f1f3; flex: none; }
.body { flex: 1; min-width: 0; margin-left: 20rpx; }
.kv { display: flex; justify-content: space-between; padding: 8rpx 0; gap: 24rpx; }
.val { text-align: right; word-break: break-all; }
.cancel { text-align: center; color: var(--sub); padding: 30rpx; }
</style>
