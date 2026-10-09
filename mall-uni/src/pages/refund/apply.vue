<script setup>
import { ref, computed } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getRefundable, applyRefund, uploadImage } from '@/api'
import { money } from '@/utils/app'
import { usePageTitle, toast } from '@/utils/page'

const { t } = useI18n()
usePageTitle('refund.apply')

const orderNo = ref('')
const info = ref(null)
const currency = ref('')
// target：'whole' 整单，或商品行 ID
const target = ref(null)
const qty = ref(1)
const type = ref(1)
const reason = ref('')
const submitting = ref(false)
// 凭证图片：已上传成功的地址，最多 6 张
const MAX_IMAGES = 6
const images = ref([])
const uploading = ref(false)

const items = computed(() => (info.value?.items || []).filter((i) => i.available_qty > 0))
const item = computed(() => items.value.find((i) => i.item_id === target.value) || null)
// 退货退款只有已发货的商品可以选
const canReturn = computed(() => !!item.value?.can_return)

function pick(v) {
  target.value = v
  qty.value = 1
  type.value = 1
}
function step(n) {
  if (!item.value) return
  qty.value = Math.min(item.value.available_qty, Math.max(1, qty.value + n))
}
function addImages() {
  if (uploading.value || images.value.length >= MAX_IMAGES) return
  uni.chooseImage({
    count: MAX_IMAGES - images.value.length,
    sizeType: ['compressed'],
    success: async (res) => {
      uploading.value = true
      uni.showLoading({ title: t('refund.uploading') })
      try {
        // 逐张上传：某一张失败（提示已弹出）就停下，已传好的保留
        for (const path of res.tempFilePaths.slice(0, MAX_IMAGES - images.value.length)) {
          const r = await uploadImage(path, 'refund')
          images.value.push(r.url)
        }
      } catch (e) {
        // 提示已由 upload 弹出
      } finally {
        uni.hideLoading()
        uploading.value = false
      }
    },
  })
}
function removeImage(i) {
  images.value.splice(i, 1)
}
function preview(i) {
  uni.previewImage({ urls: images.value, current: i })
}
async function submit() {
  if (submitting.value || uploading.value) return
  if (target.value === null) return toast(t('refund.needItem'))
  if (!reason.value.trim()) return toast(t('refund.needReason'))
  submitting.value = true
  try {
    const data = target.value === 'whole'
      ? { type: 1, reason: reason.value.trim(), images: images.value }
      : { order_item_id: target.value, qty: qty.value, type: type.value, reason: reason.value.trim(), images: images.value }
    const r = await applyRefund(orderNo.value, data)
    toast(t('refund.submitted'))
    setTimeout(() => uni.redirectTo({ url: `/pages/refund/detail?refund_no=${r.refund_no}` }), 400)
  } finally {
    submitting.value = false
  }
}

onLoad(async (q) => {
  orderNo.value = q.order_no || ''
  currency.value = q.currency || ''
  info.value = await getRefundable(orderNo.value)
  // 只有一种选择时直接选中
  if (info.value.whole_order.allowed && !items.value.length) target.value = 'whole'
  else if (!info.value.whole_order.allowed && items.value.length === 1) target.value = items.value[0].item_id
})
</script>

<template>
  <view v-if="info" class="page">
    <view v-if="!info.whole_order.allowed && !items.length" class="empty">{{ info.reason || t('refund.none') }}</view>

    <template v-else>
      <view v-if="info.whole_order.allowed" class="card opt" :class="{ on: target === 'whole' }" @click="pick('whole')">
        <view class="radio" :class="{ on: target === 'whole' }" />
        <view class="body">
          <view class="strong">{{ t('refund.whole') }}　<text class="price">{{ money(info.whole_order.amount, currency) }}</text></view>
          <view class="sub">{{ t('refund.wholeTip') }}</view>
        </view>
      </view>

      <view class="section">{{ t('refund.chooseItem') }}</view>
      <view v-for="i in items" :key="i.item_id" class="card opt" :class="{ on: target === i.item_id }" @click="pick(i.item_id)">
        <view class="radio" :class="{ on: target === i.item_id }" />
        <image :src="i.image" mode="aspectFill" class="thumb" />
        <view class="body">
          <view class="ellipsis-2">{{ i.title }}</view>
          <view class="sub ellipsis">{{ i.spec_text }}</view>
          <view class="sub">{{ t('refund.available', { n: i.available_qty }) }}　{{ t('refund.maxAmount', { amount: money(i.max_amount, currency) }) }}</view>
        </view>
      </view>

      <view v-if="item" class="card">
        <view class="kv">
          <text>{{ t('refund.type') }}</text>
          <view class="types">
            <text class="type" :class="{ on: type === 1 }" @click="type = 1">{{ t('refundType.1') }}</text>
            <text v-if="canReturn" class="type" :class="{ on: type === 2 }" @click="type = 2">{{ t('refundType.2') }}</text>
          </view>
        </view>
        <view class="kv">
          <text>{{ t('refund.qty') }}</text>
          <view class="stepper"><view class="st" @click="step(-1)">−</view><view class="num">{{ qty }}</view><view class="st" @click="step(1)">＋</view></view>
        </view>
        <view class="sub">{{ t('refund.amountTip') }}</view>
      </view>

      <view class="card">
        <view class="strong" style="margin-bottom: 12rpx">{{ t('refund.reason') }}</view>
        <textarea v-model="reason" class="textarea" maxlength="255" :placeholder="t('refund.reasonPh')" />
        <view class="sub" style="margin: 20rpx 0 12rpx">{{ t('refund.imagesTip', { n: MAX_IMAGES }) }}</view>
        <view class="pics">
          <view v-for="(u, i) in images" :key="u" class="pic">
            <image :src="u" mode="aspectFill" class="pic-img" @click="preview(i)" />
            <view class="pic-del" @click.stop="removeImage(i)">×</view>
          </view>
          <view v-if="images.length < MAX_IMAGES" class="pic pic-add" @click="addImages">＋</view>
        </view>
      </view>

      <view class="btn submit" :class="{ disabled: submitting }" @click="submit">{{ t('refund.submit') }}</view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
.page { padding-bottom: 60rpx; }
.section { padding: 20rpx 32rpx 0; color: #606266; }
.opt { display: flex; align-items: center; border: 2rpx solid transparent; }
.opt.on { border-color: var(--theme); }
.radio { width: 36rpx; height: 36rpx; border-radius: 50%; border: 3rpx solid #c0c4cc; flex: none; margin-right: 20rpx; box-sizing: border-box; }
.radio.on { border-color: var(--theme); background: var(--theme); box-shadow: inset 0 0 0 6rpx #fff; }
.thumb { width: 120rpx; height: 120rpx; border-radius: 12rpx; background: #f0f1f3; flex: none; margin-right: 20rpx; }
.body { flex: 1; min-width: 0; }
.strong { font-weight: 600; }
.kv { display: flex; justify-content: space-between; align-items: center; padding: 12rpx 0; }
.types { display: flex; gap: 16rpx; }
.type { padding: 8rpx 24rpx; border-radius: 28rpx; background: #f5f6f8; border: 2rpx solid transparent; font-size: 26rpx; }
.type.on { background: #fdecec; border-color: var(--theme); color: var(--theme); }
.stepper { display: flex; align-items: center; }
.st { width: 56rpx; height: 56rpx; line-height: 52rpx; text-align: center; background: #f5f6f8; border-radius: 8rpx; font-size: 32rpx; }
.num { min-width: 72rpx; text-align: center; }
.textarea { width: 100%; height: 180rpx; background: #f5f6f8; border-radius: 12rpx; padding: 16rpx; box-sizing: border-box; font-size: 28rpx; }
.pics { display: flex; flex-wrap: wrap; gap: 16rpx; }
.pic { position: relative; width: 150rpx; height: 150rpx; border-radius: 12rpx; background: #f5f6f8; }
.pic-img { width: 100%; height: 100%; border-radius: 12rpx; }
.pic-del { position: absolute; top: -12rpx; right: -12rpx; width: 40rpx; height: 40rpx; line-height: 36rpx; text-align: center; border-radius: 50%; background: rgba(0, 0, 0, 0.6); color: #fff; font-size: 28rpx; }
.pic-add { display: flex; align-items: center; justify-content: center; color: #c0c4cc; font-size: 56rpx; border: 2rpx dashed #dcdfe6; box-sizing: border-box; }
.submit { margin: 30rpx 24rpx; }
</style>
