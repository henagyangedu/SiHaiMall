<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { getStockSubscribes, removeStockSubscribe } from '@/api'

// 我的到货提醒：订阅过的缺货规格。等待到货 / 已到货（已经通知过）/ 已下架
const { t } = useI18n()
const list = ref([])
const page = reactive({ no: 1, size: 10, total: 0, loading: false })

async function load() {
  page.loading = true
  try {
    const d = await getStockSubscribes({ page: page.no, size: page.size })
    list.value = d.list
    page.total = d.total
  } finally {
    page.loading = false
  }
}
async function remove(r) {
  await removeStockSubscribe(r.sku_id)
  // 这一页删空了就回到上一页
  if (list.value.length === 1 && page.no > 1) page.no -= 1
  load()
}
const stateKey = (r) => (r.invalid ? 'invalid' : (r.in_stock ? 'arrived' : 'waiting'))

onMounted(load)
</script>

<template>
  <div class="panel">
    <div class="panel-title">{{ t('restock.title') }}</div>
    <div v-loading="page.loading" class="rows">
      <div v-for="r in list" :key="r.sku_id" class="item" :class="{ invalid: r.invalid }">
        <div class="cover"><img v-if="r.cover" :src="r.cover" :alt="r.title" loading="lazy" /></div>
        <div class="body">
          <!-- 已下架的商品不能点进去 -->
          <component :is="r.invalid ? 'div' : 'router-link'" :to="{ name: 'goods-detail', params: { id: r.goods_id } }" class="title ellipsis">{{ r.title }}</component>
          <div v-if="r.spec_text" class="sub">{{ r.spec_text }}</div>
          <div v-if="r.price" class="price">{{ r.symbol }}{{ r.price }}</div>
        </div>
        <span class="state" :class="stateKey(r)">{{ t(`restock.${stateKey(r)}`) }}</span>
        <div class="ops">
          <router-link v-if="r.in_stock" :to="{ name: 'goods-detail', params: { id: r.goods_id } }"><el-button type="primary" size="small">{{ t('restock.buy') }}</el-button></router-link>
          <a class="remove" @click="remove(r)">{{ t(r.status === 'waiting' && !r.invalid ? 'restock.cancel' : 'fav.remove') }}</a>
        </div>
      </div>
    </div>
    <div v-if="!page.loading && !list.length" class="empty">{{ t('restock.empty') }}</div>
    <div v-if="page.total > page.size" class="pager">
      <el-pagination v-model:current-page="page.no" :page-size="page.size" :total="page.total" layout="prev, pager, next" background @current-change="load" />
    </div>
  </div>
</template>

<style scoped>
.rows { min-height: 80px; }
.item { display: flex; align-items: center; gap: 16px; padding: 16px 0; border-bottom: 1px solid var(--line); }
.item:last-child { border-bottom: none; }
.cover { width: 72px; height: 72px; flex: none; border-radius: 8px; overflow: hidden; background: var(--cream-deep); }
.cover img { width: 100%; height: 100%; object-fit: cover; display: block; }
.body { flex: 1; min-width: 0; }
.title { display: block; color: var(--text); }
a.title:hover { color: var(--theme); }
.invalid .title { color: var(--sub); }
.price { margin-top: 4px; }
.state { flex: none; width: 90px; text-align: center; font-size: 13px; color: var(--sub); }
.state.arrived { color: var(--theme); font-weight: 600; }
.ops { flex: none; width: 170px; display: flex; justify-content: flex-end; align-items: center; gap: 16px; }
.remove { color: var(--sub); font-size: 13px; cursor: pointer; }
.remove:hover { color: var(--theme); }
</style>
