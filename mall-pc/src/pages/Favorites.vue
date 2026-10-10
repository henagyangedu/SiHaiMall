<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { getFavorites, removeFavorite } from '@/api'

const { t } = useI18n()
const list = ref([])
const page = reactive({ no: 1, size: 12, total: 0, loading: false })

async function load() {
  page.loading = true
  try {
    const d = await getFavorites({ page: page.no, size: page.size })
    list.value = d.list
    page.total = d.total
  } finally {
    page.loading = false
  }
}
async function remove(g) {
  await removeFavorite(g.id)
  // 这一页删空了就回到上一页
  if (list.value.length === 1 && page.no > 1) page.no -= 1
  load()
}

onMounted(load)
</script>

<template>
  <div class="panel">
    <div class="panel-title">{{ t('fav.title') }}</div>
    <div v-loading="page.loading" class="grid">
      <div v-for="g in list" :key="g.id" class="fav" :class="{ invalid: g.invalid }">
        <!-- 失效的商品（下架或删除了）不能点进去 -->
        <component :is="g.invalid ? 'div' : 'router-link'" :to="{ name: 'goods-detail', params: { id: g.id } }" class="cover">
          <img v-if="g.cover" :src="g.cover" :alt="g.title" loading="lazy" />
          <div v-if="g.invalid" class="mask">{{ t('fav.invalid') }}</div>
        </component>
        <div class="title ellipsis">{{ g.title }}</div>
        <div class="foot">
          <span v-if="!g.invalid" class="price">{{ g.symbol }}{{ g.price }}</span>
          <span v-else class="sub">{{ t('fav.invalidTip') }}</span>
          <a class="remove" @click="remove(g)">{{ t('fav.remove') }}</a>
        </div>
      </div>
    </div>
    <div v-if="!page.loading && !list.length" class="empty">{{ t('fav.empty') }}</div>
    <div v-if="page.total > page.size" class="pager">
      <el-pagination v-model:current-page="page.no" :page-size="page.size" :total="page.total" layout="prev, pager, next" background @current-change="load" />
    </div>
  </div>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; min-height: 80px; }
.fav { border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; }
.cover { position: relative; display: block; padding-top: 80%; background: var(--cream-deep); }
.cover img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.mask { position: absolute; inset: 0; background: rgba(41, 43, 39, 0.5); color: #fff; display: flex; align-items: center; justify-content: center; }
.title { padding: 12px 14px 0; }
.invalid .title { color: var(--sub); }
.foot { display: flex; justify-content: space-between; align-items: center; padding: 8px 14px 14px; }
.price { font-size: 17px; }
.remove { color: var(--sub); font-size: 13px; cursor: pointer; }
.remove:hover { color: var(--theme); }
</style>
