<script setup>
import { ref, reactive, watch } from 'vue'
import { onLoad, onShow, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getCategories, getGoodsList } from '@/api'
import { usePageTitle } from '@/utils/page'
import GoodsGrid from '@/components/GoodsGrid.vue'

const { t } = useI18n()
usePageTitle('tab.category')

const categories = ref([])
const current = ref(0) // 0 = 全部
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
    const d = await getGoodsList({ category_id: current.value || '', sort: sortKey, page: page.no, size: 10 })
    list.value = reset ? d.list : list.value.concat(d.list)
    page.total = d.total
    page.no += 1
  } finally {
    page.loading = false
  }
}
function pick(id) {
  current.value = id
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
watch(() => [state.lang, state.currency], async () => { await loadCategories(); loadGoods(true) })
</script>

<template>
  <view class="wrap">
    <scroll-view scroll-y class="side">
      <view class="cat" :class="{ on: current === 0 }" @click="pick(0)">{{ t('category.all') }}</view>
      <template v-for="c in categories" :key="c.id">
        <view class="cat" :class="{ on: current === c.id }" @click="pick(c.id)">{{ c.name }}</view>
        <view v-for="s in c.children" :key="s.id" class="cat child" :class="{ on: current === s.id }" @click="pick(s.id)">{{ s.name }}</view>
      </template>
    </scroll-view>

    <view class="main">
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
.cat { padding: 28rpx 16rpx; text-align: center; font-size: 26rpx; color: #606266; border-left: 6rpx solid transparent; }
.cat.child { font-size: 24rpx; padding: 20rpx 16rpx; color: var(--sub); }
.cat.on { background: #f5f6f8; color: var(--theme); border-left-color: var(--theme); font-weight: 600; }
.main { flex: 1; min-width: 0; }
.sorts { display: flex; background: #fff; margin: 0 0 12rpx 2rpx; }
.sort { flex: 1; text-align: center; padding: 22rpx 0; font-size: 26rpx; color: #606266; }
.sort.on { color: var(--theme); font-weight: 600; }
.foot { text-align: center; padding: 30rpx 0 40rpx; }
</style>
