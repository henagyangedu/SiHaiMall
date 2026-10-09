<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getGoods, addCart } from '@/api'
import { needLogin } from '@/utils/request'
import { refreshCartCount } from '@/utils/app'
import { usePageTitle, toast, tab } from '@/utils/page'

const { t } = useI18n()
usePageTitle('goods.detail')

const id = ref(0)
const goods = ref(null)
const picked = reactive({}) // 规格项 ID => 选中的规格值 ID
const qty = ref(1)
const failed = ref(false)

async function load() {
  try {
    goods.value = await getGoods(id.value)
    // 单规格商品没有可选项；多规格默认不选，让买家自己选
    Object.keys(picked).forEach((k) => delete picked[k])
  } catch (e) {
    failed.value = true
  }
}

// 选齐了规格才能确定是哪个 SKU；单规格直接取唯一的那个
const sku = computed(() => {
  const g = goods.value
  if (!g) return null
  if (!g.specs.length) return g.skus[0] || null
  const ids = g.specs.map((s) => picked[s.id])
  if (ids.some((v) => !v)) return null
  return g.skus.find((k) => k.spec_value_ids.length === ids.length && ids.every((v) => k.spec_value_ids.includes(v))) || null
})
const images = computed(() => {
  const g = goods.value
  if (!g) return []
  const list = g.images.length ? g.images : (g.cover ? [g.cover] : [])
  return sku.value?.image && !list.includes(sku.value.image) ? [sku.value.image, ...list] : list
})
// 没选规格时显示价格区间的最低价
const price = computed(() => {
  if (sku.value) return sku.value.price
  const all = (goods.value?.skus || []).map((k) => Number(k.price))
  return all.length ? Math.min(...all).toFixed(2) : '0.00'
})
const maxQty = computed(() => Math.max(1, Math.min(sku.value ? sku.value.stock : 999, 999)))
watch(maxQty, (m) => { if (qty.value > m) qty.value = m })

function pick(specId, valueId) {
  picked[specId] = picked[specId] === valueId ? 0 : valueId
}
function step(n) {
  qty.value = Math.min(maxQty.value, Math.max(1, qty.value + n))
}
// 加购和立即购买共用的检查：规格选齐、库存够
function ready() {
  if (!sku.value) { toast(t('goods.chooseSpec')); return false }
  if (sku.value.stock < qty.value) { toast(t('goods.noStock')); return false }
  return true
}
function onAddCart() {
  if (!ready()) return
  needLogin(async () => {
    await addCart(sku.value.id, qty.value)
    toast(t('goods.added'))
    refreshCartCount()
  })
}
function onBuy() {
  if (!ready()) return
  needLogin(() => uni.navigateTo({ url: `/pages/checkout/index?sku_id=${sku.value.id}&qty=${qty.value}` }))
}

onLoad((q) => {
  id.value = Number(q.id || 0)
  load()
})
watch(() => [state.lang, state.currency], load)
</script>

<template>
  <view v-if="goods" class="page">
    <swiper v-if="images.length" class="swiper" circular indicator-dots indicator-active-color="#e4393c">
      <swiper-item v-for="(u, i) in images" :key="i"><image :src="u" mode="aspectFill" class="img" /></swiper-item>
    </swiper>

    <view class="card">
      <view class="price big">{{ goods.symbol }}{{ price }}</view>
      <view class="title">{{ goods.title }}</view>
      <view v-if="goods.subtitle" class="sub">{{ goods.subtitle }}</view>
      <view class="sub" style="margin-top: 8rpx">{{ t('home.sold', { n: goods.sales }) }}<text v-if="sku">　{{ t('goods.stock', { n: sku.stock }) }}</text></view>
    </view>

    <view class="card">
      <view v-for="s in goods.specs" :key="s.id" class="spec">
        <view class="spec-name">{{ s.name }}</view>
        <view class="values">
          <view v-for="v in s.values" :key="v.id" class="value" :class="{ on: picked[s.id] === v.id }" @click="pick(s.id, v.id)">{{ v.name }}</view>
        </view>
      </view>
      <view class="qty-row">
        <text>{{ t('goods.qty') }}</text>
        <view class="stepper">
          <view class="st" :class="{ off: qty <= 1 }" @click="step(-1)">−</view>
          <view class="num">{{ qty }}</view>
          <view class="st" :class="{ off: qty >= maxQty }" @click="step(1)">＋</view>
        </view>
      </view>
    </view>

    <view v-if="goods.description" class="card">
      <view class="section">{{ t('goods.description') }}</view>
      <rich-text :nodes="goods.description" class="desc" />
    </view>

    <view class="bar safe-bottom">
      <view class="cart-link" @click="tab('/pages/cart/index')">
        <image src="/static/tab/cart.png" class="cart-icon" /><text v-if="state.cartCount" class="badge">{{ state.cartCount }}</text>
      </view>
      <view class="btn plain flex" @click="onAddCart">{{ t('goods.addCart') }}</view>
      <view class="btn flex" @click="onBuy">{{ t('goods.buyNow') }}</view>
    </view>
  </view>
  <view v-else-if="failed" class="empty">{{ t('goods.offShelf') }}</view>
</template>

<style lang="scss" scoped>
.page { padding-bottom: 160rpx; }
.swiper { height: 750rpx; background: #fff; }
.img { width: 100%; height: 100%; }
.big { font-size: 44rpx; }
.title { font-size: 32rpx; font-weight: 600; margin: 8rpx 0; }
.spec { margin-bottom: 24rpx; }
.spec-name { color: #606266; margin-bottom: 12rpx; }
.values { display: flex; flex-wrap: wrap; gap: 16rpx; }
.value { padding: 10rpx 28rpx; border-radius: 32rpx; background: #f5f6f8; border: 2rpx solid transparent; font-size: 26rpx; }
.value.on { background: #fdecec; border-color: var(--theme); color: var(--theme); }
.qty-row { display: flex; justify-content: space-between; align-items: center; }
.stepper { display: flex; align-items: center; }
.st { width: 60rpx; height: 60rpx; line-height: 56rpx; text-align: center; background: #f5f6f8; border-radius: 8rpx; font-size: 34rpx; }
.st.off { color: #c0c4cc; }
.num { min-width: 80rpx; text-align: center; }
.section { font-weight: 600; margin-bottom: 16rpx; }
.desc { font-size: 28rpx; line-height: 1.7; word-break: break-word; }
.bar { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; display: flex; align-items: center; gap: 16rpx; padding: 16rpx 24rpx; box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.05); }
.cart-link { position: relative; width: 80rpx; text-align: center; }
.cart-icon { width: 48rpx; height: 48rpx; }
.badge { position: absolute; top: -10rpx; right: 0; background: var(--theme); color: #fff; font-size: 20rpx; min-width: 32rpx; height: 32rpx; line-height: 32rpx; border-radius: 16rpx; padding: 0 6rpx; }
.flex { flex: 1; }
</style>
