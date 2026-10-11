<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { onLoad, onShow, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getCategories, getGoodsList } from '@/api'
import { usePageTitle } from '@/utils/page'
import GoodsGrid from '@/components/GoodsGrid.vue'

const { t } = useI18n()
usePageTitle('tab.category')

const categories = ref([])
// 左边只列一级分类，选中后它的二级分类排在右边商品上方；商品按“选中的二级，没选就按一级”筛
const top = ref(0) // 选中的一级分类，0 = 全部商品
const sub = ref(0) // 选中的二级分类，0 = 这个一级分类下的全部
const subs = computed(() => categories.value.find((c) => c.id === top.value)?.children || [])
const sort = ref('')
const priceAsc = ref(true)
const list = ref([])
const page = reactive({ no: 1, total: 0, loading: false })
const SORTS = [
  { key: '', label: 'category.sortDefault' }, { key: 'sales', label: 'category.sortSales' },
  { key: 'price', label: 'category.sortPrice' }, { key: 'new', label: 'category.sortNew' },
]

async function loadCategories() {
  try { categories.value = await getCategories() } catch (e) { categories.value = [] }
}
async function loadGoods(reset = false) {
  if (page.loading) return
  if (reset) { page.no = 1; list.value = [] }
  page.loading = true
  try {
    const sortKey = sort.value === 'price' ? (priceAsc.value ? 'price_asc' : 'price_desc') : sort.value
    const d = await getGoodsList({ category_id: sub.value || top.value || '', sort: sortKey, page: page.no, size: 10 })
    list.value = reset ? d.list : list.value.concat(d.list)
    page.total = d.total
    page.no += 1
  } finally {
    page.loading = false
  }
}
function pickTop(id) {
  top.value = id
  sub.value = 0
  loadGoods(true)
}
function pickSub(id) {
  sub.value = id
  loadGoods(true)
}
/** 按分类 ID 选中（从首页跳过来时用）：可能是一级，也可能是二级 */
function pick(id) {
  const parent = categories.value.find((c) => (c.children || []).some((x) => x.id === id))
  top.value = parent ? parent.id : id
  sub.value = parent ? id : 0
  loadGoods(true)
}
function pickSort(key) {
  if (key === 'price' && sort.value === 'price') priceAsc.value = !priceAsc.value
  sort.value = key
  loadGoods(true)
}

onLoad(async () => {
  await loadCategories()
  loadGoods(true)
})
// 从首页轮播图跳过来时，选中指定的分类
onShow(() => {
  if (state.pendingCategoryId) {
    const id = state.pendingCategoryId
    state.pendingCategoryId = 0
    pick(id)
  }
})
onReachBottom(() => { if (list.value.length < page.total) loadGoods() })
watch(() => [state.lang, state.currency], async () => {
  await loadCategories()
  // 选中的分类被删掉或停用了，就回到全部
  if (top.value && !categories.value.some((c) => c.id === top.value)) { top.value = 0; sub.value = 0 }
  if (sub.value && !subs.value.some((c) => c.id === sub.value)) sub.value = 0
  loadGoods(true)
})
</script>

<template>
  <view class="wrap">
    <scroll-view scroll-y class="side">
      <view class="cat" :class="{ on: top === 0 }" @click="pickTop(0)">{{ t('category.all') }}</view>
      <view v-for="c in categories" :key="c.id" class="cat" :class="{ on: top === c.id }" @click="pickTop(c.id)">{{ c.name }}</view>
    </scroll-view>

    <view class="main">
      <view v-if="subs.length" class="subs">
        <view class="chip" :class="{ on: sub === 0 }" @click="pickSub(0)">{{ t('common.all') }}</view>
        <view v-for="c in subs" :key="c.id" class="chip" :class="{ on: sub === c.id }" @click="pickSub(c.id)">{{ c.name }}</view>
      </view>
      <view class="sorts">
        <view v-for="s in SORTS" :key="s.key" class="sort" :class="{ on: sort === s.key }" @click="pickSort(s.key)">
          {{ t(s.label) }}<text v-if="s.key === 'price' && sort === 'price'">{{ priceAsc ? ' ↑' : ' ↓' }}</text>
        </view>
      </view>
      <GoodsGrid :list="list" />
      <view class="sub foot">{{ page.loading ? t('common.loading') : (list.length ? t('common.noMore') : t('common.empty')) }}</view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.wrap { display: flex; min-height: 100vh; }
.side { width: 180rpx; flex: none; background: #fff; height: calc(100vh - var(--window-top) - var(--window-bottom)); position: sticky; top: var(--window-top); }
.cat { padding: 30rpx 14rpx; text-align: center; font-size: 26rpx; line-height: 1.35; color: #6a665e; border-left: 6rpx solid transparent; word-break: break-word; }
.cat.on { background: var(--bg); color: var(--theme); border-left-color: var(--theme); font-weight: 600; }
.subs { display: flex; flex-wrap: wrap; gap: 14rpx; background: #fff; margin: 0 0 2rpx 2rpx; padding: 20rpx 20rpx 22rpx; }
.chip { padding: 10rpx 24rpx; border-radius: 999rpx; background: var(--cream-deep); color: #6a665e; font-size: 24rpx; line-height: 1.5; max-width: 100%; box-sizing: border-box; }
.chip.on { background: var(--theme); color: #fff; }
.main { flex: 1; min-width: 0; }
/* 排序的文字在有些语言里比较长：不换行，放不下时可以横向滑动 */
.sorts { display: flex; background: #fff; margin: 0 0 12rpx 2rpx; overflow-x: auto; }
.sort { flex: 1 0 auto; text-align: center; padding: 22rpx 14rpx; font-size: 26rpx; color: #6a665e; white-space: nowrap; }
.sort.on { color: var(--theme); font-weight: 600; }
.foot { text-align: center; padding: 30rpx 0 40rpx; }
</style>
