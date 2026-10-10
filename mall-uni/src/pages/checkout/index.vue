<script setup>
import { ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { checkout, createOrder } from '@/api'
import { money, refreshCartCount, couponText } from '@/utils/app'
import { usePageTitle, toast, nav } from '@/utils/page'

const { t } = useI18n()
usePageTitle('checkout.title')

// 三种入口：购物车结算（cart_ids）、购买套餐（sku_ids，每样 1 件）、商品页立即购买（sku_id + qty）
const source = ref({})
const addressId = ref(0)
const usePoints = ref(false)
// 用哪张优惠券：null 让服务端自动选减得最多的，0 不用，其余是会员券的 ID
const couponChoice = ref(null)
const remark = ref('')
const data = ref(null)
const submitting = ref(false)

const payload = () => ({
  ...source.value, ...(addressId.value ? { address_id: addressId.value } : {}), use_points: usePoints.value,
  ...(couponChoice.value === null ? {} : { member_coupon_id: couponChoice.value }),
})
const usableCoupons = () => (data.value?.coupons || []).filter((c) => c.usable)
// 选券：列出这一单能用的券（系统的选择列表最多放 6 项，所以最多列 5 张，减得多的在前）和“不使用”
function chooseCoupon() {
  const usable = usableCoupons().slice(0, 5)
  if (!usable.length) return
  uni.showActionSheet({
    title: t('coupon.choose'),
    itemList: [...usable.map((c) => `${c.name}（-${money(c.amount, data.value.currency)}）`), t('coupon.none')],
    success: ({ tapIndex }) => {
      couponChoice.value = tapIndex < usable.length ? usable[tapIndex].id : 0
      load()
    },
  })
}

// 金额、运费、区域价都由服务端按所选地址算好，这里只负责展示
async function load() {
  data.value = await checkout(payload())
  if (data.value.address && !addressId.value) addressId.value = data.value.address.id
}
function togglePoints() {
  usePoints.value = !usePoints.value
  load()
}
async function submit() {
  if (submitting.value) return
  if (data.value.need_address && !addressId.value) return toast(t('checkout.needAddress'))
  if (data.value.problems.length) return toast(data.value.problems[0].msg)
  submitting.value = true
  try {
    // 下单用的就是预览里算出来的那张券，保证买家看到的和实际扣的一致
    const order = await createOrder({ ...payload(), member_coupon_id: data.value.member_coupon_id, remark: remark.value.trim() })
    refreshCartCount()
    uni.redirectTo({ url: `/pages/order/detail?order_no=${order.order_no}&pay=1` })
  } finally {
    submitting.value = false
  }
}

onLoad((q) => {
  source.value = q.cart_ids
    ? { cart_ids: String(q.cart_ids).split(',').map(Number) }
    : q.sku_ids
      ? { items: String(q.sku_ids).split(',').map(Number).filter(Boolean).map((id) => ({ sku_id: id, qty: 1 })) }
      : { items: [{ sku_id: Number(q.sku_id), qty: Number(q.qty || 1) }] }
})
// 从地址列表选完地址回来时重新计算
onShow(() => {
  if (state.pickedAddressId) {
    addressId.value = state.pickedAddressId
    state.pickedAddressId = 0
  }
  load()
})
</script>

<template>
  <view v-if="data" class="page">
    <view v-if="data.need_address" class="card addr" @click="nav('/pages/address/list?select=1')">
      <template v-if="data.address">
        <view><text class="name">{{ data.address.name }}</text><text class="sub">　+{{ data.address.phone_area }} {{ data.address.phone }}</text></view>
        <view class="sub">{{ [data.address.province, data.address.city, data.address.district, data.address.address, data.address.address2].filter(Boolean).join(' ') }}</view>
      </template>
      <view v-else class="add">＋ {{ t('checkout.addAddress') }}</view>
      <text class="arrow">›</text>
    </view>

    <view class="card">
      <view v-for="i in data.items" :key="i.sku_id" class="item">
        <image :src="i.image" mode="aspectFill" class="thumb" />
        <view class="body">
          <view class="ellipsis-2">{{ i.title }}</view>
          <view class="sub ellipsis">{{ i.spec_text }}</view>
          <view class="line"><text class="price">{{ money(i.price, data.currency) }}</text><text class="sub">× {{ i.qty }}</text></view>
        </view>
      </view>
      <view v-if="data.price_region" class="sub region">{{ t('checkout.region', { name: data.price_region.name }) }}</view>
      <view v-for="(p, i) in data.problems" :key="i" class="problem">{{ p.msg }}</view>
    </view>

    <view class="card">
      <view class="kv"><text>{{ t('checkout.goods') }}</text><text>{{ money(data.goods_amount, data.currency) }}</text></view>
      <view class="kv"><text>{{ t('checkout.shipping') }}</text><text>{{ Number(data.shipping_fee) > 0 ? money(data.shipping_fee, data.currency) : t('checkout.free') }}</text></view>
      <view v-if="Number(data.bundle_amount) > 0" class="kv bundle-row"><text>{{ t('bundle.row') }}</text><text class="price">-{{ money(data.bundle_amount, data.currency) }}</text></view>
      <view v-if="Number(data.discount_amount) > 0" class="kv"><text>{{ t('checkout.discount') }}</text><text class="price">-{{ money(data.discount_amount, data.currency) }}</text></view>
      <view v-if="data.coupons.length" class="kv" @click="chooseCoupon">
        <text>{{ t('coupon.row') }}</text>
        <text v-if="data.member_coupon_id" class="price">-{{ money(data.coupon_amount, data.currency) }} ›</text>
        <text v-else class="sub">{{ usableCoupons().length ? t('coupon.usableCount', { n: usableCoupons().length }) : t('coupon.noUsable') }} ›</text>
      </view>
      <view v-if="data.points.enabled && data.points.usable_points > 0" class="kv" @click="togglePoints">
        <text>{{ t('checkout.usePoints', { n: data.points.usable_points, amount: money(data.points.deduct_amount, data.currency) }) }}</text>
        <view class="switch" :class="{ on: usePoints }"><view class="dot" /></view>
      </view>
      <view v-if="data.earn_points > 0" class="kv"><text class="sub">{{ t('checkout.earnPoints', { n: data.earn_points }) }}</text></view>
      <input v-model="remark" class="remark" maxlength="500" :placeholder="t('checkout.remark')" />
    </view>

    <view class="bar safe-bottom">
      <view class="sum">{{ t('checkout.pay') }} <text class="price total">{{ money(data.pay_amount, data.currency) }}</text></view>
      <view class="btn go" :class="{ disabled: submitting }" @click="submit">{{ t('checkout.submit') }}</view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding-bottom: 160rpx; }
.addr { position: relative; padding-right: 60rpx; }
.name { font-weight: 600; font-size: 30rpx; }
.add { color: var(--theme); }
.arrow { position: absolute; right: 24rpx; top: 50%; transform: translateY(-50%); color: #c9c2b5; font-size: 40rpx; }
.item { display: flex; margin-bottom: 20rpx; }
.thumb { width: 140rpx; height: 140rpx; border-radius: 12rpx; background: #efe8dc; flex: none; }
.body { flex: 1; min-width: 0; margin-left: 20rpx; }
.line { display: flex; justify-content: space-between; margin-top: 8rpx; }
.region { margin-top: 4rpx; }
.problem { color: var(--theme); font-size: 24rpx; margin-top: 8rpx; }
.kv { display: flex; justify-content: space-between; align-items: center; padding: 12rpx 0; }
.switch { width: 84rpx; height: 48rpx; border-radius: 24rpx; background: #ded6c8; padding: 4rpx; box-sizing: border-box; }
.switch.on { background: var(--theme); }
.dot { width: 40rpx; height: 40rpx; border-radius: 50%; background: #fff; transition: transform 0.2s; }
.switch.on .dot { transform: translateX(36rpx); }
.remark { margin-top: 12rpx; padding: 16rpx 20rpx; background: #f3ede2; border-radius: 12rpx; height: 44rpx; }
.bar { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; display: flex; align-items: center; padding: 16rpx 24rpx; box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.05); }
.sum { flex: 1; }
.total { font-size: 36rpx; }
.go { padding: 0 48rpx; height: 80rpx; line-height: 80rpx; }
</style>
