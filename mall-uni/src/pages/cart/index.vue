<script setup>
import { ref, computed, watch } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state, currencySymbol } from '@/store'
import { getCart, setCartQty, removeCart, clearInvalidCart } from '@/api'
import { toLogin } from '@/utils/request'
import { refreshCartCount } from '@/utils/app'
import { usePageTitle, confirm, toast, nav, tab } from '@/utils/page'

const { t } = useI18n()
usePageTitle('cart.title')

const items = ref([])
const checked = ref([]) // 选中的购物车行 ID
const loaded = ref(false)

async function load() {
  if (!state.token) { items.value = []; loaded.value = true; return }
  try {
    const d = await getCart({ auth: false })
    items.value = d.items
    // 只保留仍然可买的选中项
    const ok = d.items.filter((i) => i.valid && i.enough).map((i) => i.id)
    checked.value = checked.value.filter((id) => ok.includes(id))
    state.cartCount = d.count
  } finally {
    loaded.value = true
    refreshCartCount()
  }
}

const buyable = computed(() => items.value.filter((i) => i.valid && i.enough))
const invalid = computed(() => items.value.filter((i) => !i.valid))
const allChecked = computed(() => buyable.value.length > 0 && buyable.value.every((i) => checked.value.includes(i.id)))
// 合计只是给买家看的预估，实际金额以下单时服务端计算为准
const total = computed(() => items.value.filter((i) => checked.value.includes(i.id)).reduce((s, i) => s + Number(i.line_amount), 0).toFixed(2))

function toggle(item) {
  if (!item.valid || !item.enough) return
  const i = checked.value.indexOf(item.id)
  i >= 0 ? checked.value.splice(i, 1) : checked.value.push(item.id)
}
function toggleAll() {
  checked.value = allChecked.value ? [] : buyable.value.map((i) => i.id)
}
async function step(item, n) {
  const next = item.qty + n
  if (next < 1 || next > Math.min(item.stock, 999)) return
  await setCartQty(item.id, next)
  load()
}
async function removeChecked() {
  if (!checked.value.length) return toast(t('cart.chooseFirst'))
  if (!(await confirm(t('cart.removeConfirm')))) return
  await removeCart(checked.value)
  checked.value = []
  load()
}
async function clearInvalid() {
  await clearInvalidCart()
  load()
}
function goCheckout() {
  if (!checked.value.length) return toast(t('cart.chooseFirst'))
  uni.navigateTo({ url: `/pages/checkout/index?cart_ids=${checked.value.join(',')}` })
}

onShow(load)
watch(() => [state.lang, state.currency], load)
</script>

<template>
  <view class="page">
    <view v-if="!state.token" class="empty">
      <view>{{ t('cart.empty') }}</view>
      <view class="btn small" style="margin-top: 30rpx" @click="toLogin">{{ t('user.notLogin') }}</view>
    </view>
    <view v-else-if="loaded && !items.length" class="empty">
      <view>{{ t('cart.empty') }}</view>
      <view class="btn small" style="margin-top: 30rpx" @click="tab('/pages/index/index')">{{ t('cart.goShopping') }}</view>
    </view>

    <template v-else>
      <view v-for="i in items" :key="i.id" class="row" :class="{ off: !i.valid }">
        <view class="check" :class="{ on: checked.includes(i.id), dis: !i.valid || !i.enough }" @click="toggle(i)" />
        <image :src="i.image" mode="aspectFill" class="thumb" @click="i.goods_id && nav(`/pages/goods/detail?id=${i.goods_id}`)" />
        <view class="body">
          <view class="ellipsis-2">{{ i.title }}</view>
          <view class="sub ellipsis">{{ i.spec_text }}</view>
          <view v-if="!i.valid" class="sub">{{ t('cart.invalid') }}</view>
          <view v-else-if="!i.enough" class="warn">{{ t('cart.onlyLeft', { n: i.stock }) }}</view>
          <view class="line">
            <text class="price">{{ currencySymbol() }}{{ i.price }}</text>
            <view v-if="i.valid" class="stepper">
              <view class="st" @click="step(i, -1)">−</view><view class="num">{{ i.qty }}</view><view class="st" @click="step(i, 1)">＋</view>
            </view>
          </view>
        </view>
      </view>
      <view v-if="invalid.length" class="clear" @click="clearInvalid">{{ t('cart.clearInvalid') }}</view>
    </template>

    <view v-if="state.token && items.length" class="bar">
      <view class="all" @click="toggleAll"><view class="check" :class="{ on: allChecked }" />{{ t('cart.selectAll') }}</view>
      <text class="del" @click="removeChecked">{{ t('common.delete') }}</text>
      <view class="sum">{{ t('cart.total') }} <text class="price">{{ currencySymbol() }}{{ total }}</text></view>
      <view class="btn go" :class="{ disabled: !checked.length }" @click="goCheckout">{{ t('cart.checkout', { n: checked.length }) }}</view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.page { padding-bottom: 140rpx; }
.row { display: flex; align-items: center; background: #fff; margin: 20rpx 24rpx 0; padding: 24rpx; border-radius: 16rpx; }
.row.off { opacity: 0.55; }
.check { width: 40rpx; height: 40rpx; border-radius: 50%; border: 3rpx solid #c9c2b5; flex: none; margin-right: 20rpx; box-sizing: border-box; }
.check.on { background: var(--theme); border-color: var(--theme); box-shadow: inset 0 0 0 6rpx #fff; }
.check.dis { background: #efe8dc; }
.thumb { width: 160rpx; height: 160rpx; border-radius: 12rpx; background: #efe8dc; flex: none; }
.body { flex: 1; min-width: 0; margin-left: 20rpx; }
.warn { color: #e6a23c; font-size: 24rpx; }
.line { display: flex; justify-content: space-between; align-items: center; margin-top: 12rpx; }
.stepper { display: flex; align-items: center; }
.st { width: 52rpx; height: 52rpx; line-height: 48rpx; text-align: center; background: #f3ede2; border-radius: 8rpx; font-size: 30rpx; }
.num { min-width: 64rpx; text-align: center; }
.clear { text-align: center; color: var(--sub); padding: 30rpx; font-size: 26rpx; }
.bar { position: fixed; left: 0; right: 0; bottom: var(--window-bottom); background: #fff; display: flex; align-items: center; padding: 16rpx 24rpx; box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.05); }
.all { display: flex; align-items: center; }
.del { color: var(--sub); margin-left: 24rpx; font-size: 26rpx; }
.sum { flex: 1; text-align: right; margin-right: 20rpx; }
.go { padding: 0 36rpx; height: 76rpx; line-height: 76rpx; font-size: 28rpx; }
</style>
