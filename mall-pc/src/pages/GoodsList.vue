<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getCategories, getGoodsList } from '@/api'
import GoodsCard from '@/components/GoodsCard.vue'

// 分类浏览和关键词搜索共用：地址上的 ?category_id= / ?keyword= / ?sort= / ?page= 就是当前的筛选条件，
// 改条件就是改地址（App.vue 里按地址重建页面），所以可以直接分享链接、前进后退
const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const SORTS = [
  { key: '', label: 'category.sortDefault' }, { key: 'sales', label: 'category.sortSales' },
  { key: 'price', label: 'category.sortPrice' }, { key: 'new', label: 'category.sortNew' },
]
const categories = ref([])
const list = ref([])
// 搜索没有结果时，在提示下面给出的推荐商品
const fallback = ref([])
const page = reactive({ size: 20, total: 0, loading: false })

const keyword = computed(() => String(route.query.keyword || ''))
const categoryId = computed(() => Number(route.query.category_id || 0))
const sort = computed(() => String(route.query.sort || ''))
const pageNo = computed(() => Math.max(1, Number(route.query.page || 1)))
// 价格排序在升序、降序之间切换
const sortGroup = computed(() => (sort.value.startsWith('price') ? 'price' : sort.value))

function go(patch) {
  const q = { ...route.query, ...patch }
  Object.keys(q).forEach((k) => { if (q[k] === '' || q[k] === 0 || q[k] === undefined) delete q[k] })
  router.push({ name: 'goods', query: q })
}
function pickSort(key) {
  if (key === 'price') return go({ sort: sort.value === 'price_asc' ? 'price_desc' : 'price_asc', page: '' })
  go({ sort: key, page: '' })
}

onMounted(async () => {
  getCategories().then((d) => { categories.value = d }).catch(() => {})
  page.loading = true
  try {
    const d = await getGoodsList({ keyword: keyword.value, category_id: categoryId.value || '', sort: sort.value, page: pageNo.value, size: page.size })
    list.value = d.list
    page.total = d.total
    if (keyword.value && !d.total) fallback.value = (await getGoodsList({ size: 8 }).catch(() => ({ list: [] }))).list
  } finally {
    page.loading = false
  }
})
</script>

<template>
  <div class="wrap">
    <aside class="panel cats">
      <a class="cat" :class="{ on: !categoryId }" @click="go({ category_id: '', page: '' })">{{ t('category.all') }}</a>
      <template v-for="c in categories" :key="c.id">
        <a class="cat" :class="{ on: categoryId === c.id }" @click="go({ category_id: c.id, page: '' })">{{ c.name }}</a>
        <a v-for="s in c.children" :key="s.id" class="cat child" :class="{ on: categoryId === s.id }" @click="go({ category_id: s.id, page: '' })">{{ s.name }}</a>
      </template>
    </aside>

    <section class="main">
      <div class="panel bar">
        <div class="sorts">
          <a v-for="s in SORTS" :key="s.key" class="sort" :class="{ on: sortGroup === s.key }" @click="pickSort(s.key)">
            {{ t(s.label) }}<span v-if="s.key === 'price' && sortGroup === 'price'">{{ sort === 'price_asc' ? ' ↑' : ' ↓' }}</span>
          </a>
        </div>
        <span class="sub">
          <template v-if="keyword">{{ t('pc.searchResult', { keyword }) }}　<a class="link" @click="go({ keyword: '', page: '' })">×</a>　</template>
          {{ t('pc.totalGoods', { n: page.total }) }}
        </span>
      </div>

      <div v-loading="page.loading" class="grid">
        <GoodsCard v-for="g in list" :key="g.id" :g="g" />
      </div>
      <template v-if="!page.loading && !list.length">
        <!-- 搜索没有结果：说明一下，并给几件推荐商品 -->
        <template v-if="keyword">
          <div class="panel no-result">
            <div class="no-result-title">{{ t('search.noResult', { keyword }) }}</div>
            <div class="sub">{{ t('search.tryOther') }}</div>
          </div>
          <div v-if="fallback.length" class="grid fallback"><GoodsCard v-for="g in fallback" :key="g.id" :g="g" /></div>
        </template>
        <div v-else class="empty">{{ t('common.empty') }}</div>
      </template>
      <div v-if="page.total > page.size" class="pager">
        <el-pagination :current-page="pageNo" :page-size="page.size" :total="page.total" layout="prev, pager, next" background @current-change="(n) => go({ page: n > 1 ? n : '' })" />
      </div>
    </section>
  </div>
</template>

<style scoped>
.wrap { display: flex; gap: 16px; align-items: flex-start; }
.cats { width: 220px; flex: none; padding: 12px 0; }
.cat { display: block; padding: 8px 20px; cursor: pointer; }
.cat.child { padding-left: 36px; color: #606266; font-size: 13px; }
.cat:hover, .cat.on { color: var(--theme); }
.cat.on { background: var(--el-color-primary-light-9); font-weight: 600; }
.main { flex: 1; min-width: 0; }
.bar { display: flex; justify-content: space-between; align-items: center; padding: 12px 20px; margin-bottom: 16px; }
.sort { margin-right: 28px; cursor: pointer; }
.sort.on, .sort:hover { color: var(--theme); }
.grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; min-height: 120px; }
.grid.fallback { margin-top: 16px; }
.no-result { padding: 40px 20px; text-align: center; }
.no-result-title { margin-bottom: 8px; font-size: 16px; font-weight: 600; }
</style>
