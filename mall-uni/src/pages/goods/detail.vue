<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getGoods, addCart, getGoodsReviews, addFavorite, removeFavorite, getCoupons, addStockSubscribe, removeStockSubscribe } from '@/api'
import { couponText } from '@/utils/app'
import { needLogin } from '@/utils/request'
import { refreshCartCount } from '@/utils/app'
import { usePageTitle, toast, tab, nav, hasService, contactService } from '@/utils/page'
import ReviewItem from '@/components/ReviewItem.vue'

const { t } = useI18n()
usePageTitle('goods.detail')

const id = ref(0)
const goods = ref(null)
const picked = reactive({}) // 规格项 ID => 选中的规格值 ID
const qty = ref(1)
const failed = ref(false)
// 商品页只带出最新的两条评价，其余在评价列表页看
const reviews = ref([])
// 这个商品现在能领的优惠券（最多显示两张的面额，点进去是领券中心）
const coupons = ref([])

async function load() {
  try {
    goods.value = await getGoods(id.value)
    // 单规格商品没有可选项；多规格默认不选，让买家自己选
    Object.keys(picked).forEach((k) => delete picked[k])
  } catch (e) {
    failed.value = true
    return
  }
  getCoupons({ goods_id: id.value }).then((d) => { coupons.value = d }).catch(() => {})
  if (goods.value.review?.count) {
    getGoodsReviews(id.value, { size: 2 }).then((d) => { reviews.value = d.list }).catch(() => {})
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

// 收藏 / 取消收藏：先改界面再发请求，失败了改回去
function toggleFav() {
  needLogin(async () => {
    const g = goods.value
    g.favorited = !g.favorited
    try {
      await (g.favorited ? addFavorite(g.id) : removeFavorite(g.id))
    } catch (e) {
      g.favorited = !g.favorited
    }
  })
}

// 选中的规格缺货时，“加入购物车 / 立即购买”换成“到货提醒我”
const soldOut = computed(() => !!sku.value && sku.value.stock <= 0)
const subscribed = computed(() => !!sku.value && (goods.value.restock_sku_ids || []).includes(sku.value.id))
function toggleRestock() {
  needLogin(async () => {
    const g = goods.value
    const id = sku.value.id
    if (subscribed.value) {
      await removeStockSubscribe(id)
      g.restock_sku_ids = g.restock_sku_ids.filter((x) => x !== id)
    } else {
      await addStockSubscribe(id)
      g.restock_sku_ids = [...(g.restock_sku_ids || []), id]
      toast(t('restock.subscribedTip'))
    }
  })
}

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
// 购买套餐：套餐里的每件商品各 1 件，直接去结算
function buyBundle(b) {
  needLogin(() => uni.navigateTo({ url: `/pages/checkout/index?sku_ids=${b.items.map((i) => i.sku_id).join(',')}` }))
}

onLoad((q) => {
  id.value = Number(q.id || 0)
  load()
})
watch(() => [state.lang, state.currency], load)
</script>

<template>
  <view v-if="goods" class="page">
    <swiper v-if="images.length" class="swiper" circular indicator-dots indicator-active-color="#c56948">
      <swiper-item v-for="(u, i) in images" :key="i"><image :src="u" mode="aspectFill" class="img" /></swiper-item>
    </swiper>

    <view class="card">
      <view class="price big">{{ goods.symbol }}{{ price }}</view>
      <view class="title">{{ goods.title }}</view>
      <view v-if="goods.subtitle" class="sub">{{ goods.subtitle }}</view>
      <view class="sub" style="margin-top: 8rpx">{{ t('home.sold', { n: goods.sales }) }}<text v-if="sku">　{{ t('goods.stock', { n: sku.stock }) }}</text></view>
    </view>

    <view v-if="coupons.length" class="card coupons" @click="nav('/pages/coupon/index')">
      <text class="cp-label">{{ t('coupon.get') }}</text>
      <view class="cp-list"><text v-for="c in coupons.slice(0, 2)" :key="c.coupon_id" class="cp-tag">{{ couponText(c) }}</text></view>
      <text class="sub">›</text>
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

    <!-- 搭配套餐：和这个商品一起买立减。点“购买套餐”带着这几件直接去结算，优惠在结算页算 -->
    <view v-if="goods.bundles && goods.bundles.length" class="card bundles">
      <view class="section">{{ t('bundle.title') }}</view>
      <view v-for="b in goods.bundles" :key="b.id" class="bundle">
        <scroll-view scroll-x class="rel-scroll">
          <view class="rel-list">
            <view v-for="i in b.items" :key="i.sku_id" class="b-item" @click="nav(`/pages/goods/detail?id=${i.goods_id}`)">
              <image :src="i.image" mode="aspectFill" class="b-img" />
              <view class="ellipsis-2 b-name">{{ i.title }}</view>
              <view class="sub">{{ goods.symbol }}{{ i.price }}</view>
            </view>
          </view>
        </scroll-view>
        <view class="b-foot">
          <view>
            <view><text class="sub">{{ t('bundle.price') }}　</text><text class="price b-price">{{ goods.symbol }}{{ b.bundle_price }}</text></view>
            <view class="sub">{{ t('bundle.total') }} {{ goods.symbol }}{{ b.total }}　<text class="b-save">{{ t('bundle.save', { n: goods.symbol + b.reduce }) }}</text></view>
          </view>
          <view class="btn small" @click="buyBundle(b)">{{ t('bundle.buy') }}</view>
        </view>
      </view>
    </view>

    <!-- 配套的耗材和配件；如果这个商品本身是配件，则是适用的设备 -->
    <view v-for="[key, list] in [['related', goods.related], ['fits', goods.fits]]" :key="key">
      <view v-if="list && list.length" class="card">
        <view class="section">{{ t(`extra.${key}`) }}</view>
        <scroll-view scroll-x class="rel-scroll">
          <view class="rel-list">
            <view v-for="g in list" :key="g.id" class="rel-item" @click="nav(`/pages/goods/detail?id=${g.id}`)">
              <image :src="g.cover" mode="aspectFill" class="rel-img" lazy-load />
              <view class="rel-title ellipsis-2">{{ g.title }}</view>
              <view class="price">{{ g.symbol }}{{ g.price }}</view>
            </view>
          </view>
        </scroll-view>
      </view>
    </view>

    <view v-if="goods.review" class="card reviews">
      <view class="section rv-head" @click="goods.review.count && nav(`/pages/review/list?goods_id=${goods.id}`)">
        <text>{{ t('review.title') }}（{{ goods.review.count }}）</text>
        <text v-if="goods.review.count" class="sub">{{ goods.review.good_rate }}% {{ t('review.goodRate') }} ›</text>
      </view>
      <ReviewItem v-for="r in reviews" :key="r.id" :review="r" />
      <view v-if="!goods.review.count" class="sub rv-empty">{{ t('review.empty') }}</view>
      <view v-else-if="goods.review.count > reviews.length" class="rv-more" @click="nav(`/pages/review/list?goods_id=${goods.id}`)">{{ t('review.viewAll', { n: goods.review.count }) }}</view>
    </view>

    <view v-if="goods.params && goods.params.length" class="card">
      <view class="section">{{ t('extra.params') }}</view>
      <view v-for="(p, i) in goods.params" :key="i" class="param">
        <text class="param-name">{{ p.name }}</text><text class="param-value">{{ p.value }}</text>
      </view>
    </view>

    <view v-if="goods.description" class="card">
      <view class="section">{{ t('goods.description') }}</view>
      <rich-text :nodes="goods.description" class="desc" />
    </view>

    <view class="bar safe-bottom">
      <view v-if="hasService()" class="mini" @click="contactService"><text class="mini-icon">☏</text><text class="mini-text">{{ t('service.contact') }}</text></view>
      <view class="mini fav" :class="{ on: goods.favorited }" @click="toggleFav">
        <text class="mini-icon">{{ goods.favorited ? '♥' : '♡' }}</text><text class="mini-text">{{ t(goods.favorited ? 'fav.added' : 'fav.add') }}</text>
      </view>
      <view class="cart-link" @click="tab('/pages/cart/index')">
        <image src="/static/tab/cart.png" class="cart-icon" /><text v-if="state.cartCount" class="badge">{{ state.cartCount }}</text>
      </view>
      <!-- 选中的规格缺货：可以订阅到货提醒 -->
      <view v-if="soldOut" class="btn flex restock" :class="{ plain: subscribed }" @click="toggleRestock">{{ t(subscribed ? 'restock.cancel' : 'restock.notify') }}</view>
      <template v-else>
        <view class="btn plain flex" @click="onAddCart">{{ t('goods.addCart') }}</view>
        <view class="btn flex" @click="onBuy">{{ t('goods.buyNow') }}</view>
      </template>
    </view>
  </view>
  <view v-else-if="failed" class="empty">{{ t('goods.offShelf') }}</view>
</template>

<style lang="scss" scoped>
.page { padding-bottom: 160rpx; }
.swiper { height: 750rpx; background: #fff; }
.img { width: 100%; height: 100%; }
.big { font-size: 44rpx; }
.bundle + .bundle { border-top: 2rpx solid var(--line); margin-top: 24rpx; padding-top: 8rpx; }
.b-item { width: 180rpx; white-space: normal; }
.b-img { width: 180rpx; height: 160rpx; border-radius: 16rpx; background: var(--cream-deep); display: block; }
.b-name { margin-top: 8rpx; font-size: 24rpx; line-height: 1.4; height: 68rpx; }
.b-foot { display: flex; justify-content: space-between; align-items: center; margin-top: 20rpx; }
.b-price { font-size: 34rpx; }
.b-save { color: var(--theme); }
.rel-scroll { white-space: nowrap; margin-top: 16rpx; }
.rel-list { display: inline-flex; gap: 20rpx; }
.rel-item { width: 220rpx; white-space: normal; }
.rel-img { width: 220rpx; height: 190rpx; border-radius: 16rpx; background: var(--cream-deep); display: block; }
.rel-title { margin: 10rpx 0 4rpx; font-size: 24rpx; line-height: 1.4; height: 68rpx; }
.param { display: flex; padding: 16rpx 0; border-bottom: 2rpx solid var(--line); font-size: 26rpx; }
.param:last-child { border-bottom: none; }
.param-name { width: 200rpx; flex: none; color: var(--sub); }
.param-value { flex: 1; min-width: 0; word-break: break-all; }
.coupons { display: flex; align-items: center; }
.cp-label { color: var(--theme); font-weight: 600; margin-right: 20rpx; flex: none; }
.cp-list { flex: 1; min-width: 0; display: flex; gap: 12rpx; overflow: hidden; }
.cp-tag { padding: 4rpx 16rpx; border-radius: 8rpx; border: 2rpx solid var(--theme); color: var(--theme); font-size: 22rpx; white-space: nowrap; }
.rv-head { display: flex; justify-content: space-between; align-items: center; }
.rv-empty { padding: 16rpx 0 8rpx; }
.rv-more { text-align: center; color: var(--theme); font-size: 26rpx; padding-top: 20rpx; }
.title { font-size: 32rpx; font-weight: 600; margin: 8rpx 0; }
.spec { margin-bottom: 24rpx; }
.spec-name { color: #6a665e; margin-bottom: 12rpx; }
.values { display: flex; flex-wrap: wrap; gap: 16rpx; }
.value { padding: 10rpx 28rpx; border-radius: 32rpx; background: #f3ede2; border: 2rpx solid transparent; font-size: 26rpx; }
.value.on { background: #f9f0ec; border-color: var(--theme); color: var(--theme); }
.qty-row { display: flex; justify-content: space-between; align-items: center; }
.stepper { display: flex; align-items: center; }
.st { width: 60rpx; height: 60rpx; line-height: 56rpx; text-align: center; background: #f3ede2; border-radius: 8rpx; font-size: 34rpx; }
.st.off { color: #c9c2b5; }
.num { min-width: 80rpx; text-align: center; }
.section { font-weight: 600; margin-bottom: 16rpx; }
.desc { font-size: 28rpx; line-height: 1.7; word-break: break-word; }
.bar { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; display: flex; align-items: center; gap: 16rpx; padding: 16rpx 24rpx; box-shadow: 0 -2rpx 12rpx rgba(0, 0, 0, 0.05); }
.cart-link { position: relative; width: 80rpx; text-align: center; }
.cart-icon { width: 48rpx; height: 48rpx; }
.badge { position: absolute; top: -10rpx; right: 0; background: var(--theme); color: #fff; font-size: 20rpx; min-width: 32rpx; height: 32rpx; line-height: 32rpx; border-radius: 16rpx; padding: 0 6rpx; }
.flex { flex: 1; }
.mini { display: flex; flex-direction: column; align-items: center; width: 84rpx; flex: none; color: #6a665e; }
.mini-icon { font-size: 40rpx; line-height: 48rpx; }
.mini-text { font-size: 20rpx; white-space: nowrap; }
.mini.on { color: var(--theme); }
</style>
