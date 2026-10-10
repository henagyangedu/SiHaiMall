<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { getRefunds } from '@/api'
import { money } from '@/utils/app'

const { t } = useI18n()
const list = ref([])
const page = reactive({ no: 1, size: 10, total: 0, loading: false })

async function load() {
  page.loading = true
  try {
    const d = await getRefunds({ page: page.no, size: page.size })
    list.value = d.list
    page.total = d.total
  } finally {
    page.loading = false
  }
}

onMounted(load)
</script>

<template>
  <div class="panel">
    <div class="panel-title">{{ t('refund.list') }}</div>
    <div v-loading="page.loading" class="list">
      <router-link v-for="r in list" :key="r.refund_no" :to="{ name: 'refund-detail', params: { refundNo: r.refund_no } }" class="row">
        <div class="head">
          <span class="sub">{{ r.created_at }}　{{ t('refund.no') }} {{ r.refund_no }}</span>
          <span class="status">{{ t(`refundStatus.${r.status}`) }}</span>
        </div>
        <div class="body">
          <div class="items">
            <div v-for="(i, k) in r.items" :key="k" class="item">
              <img :src="i.image" alt="" class="thumb" />
              <div class="info"><div class="ellipsis">{{ i.title }}</div><div class="sub">{{ i.spec_text }}　× {{ i.qty }}</div></div>
            </div>
          </div>
          <div class="side">
            <div class="sub">{{ r.whole_order ? t('refund.whole') : t(`refundType.${r.type}`) }}</div>
            <div>{{ t('refund.amount') }} <span class="price">{{ money(r.refund_amount, r.currency) }}</span></div>
          </div>
        </div>
      </router-link>
      <div v-if="!page.loading && !list.length" class="empty">{{ t('common.empty') }}</div>
    </div>
    <div v-if="page.total > page.size" class="pager">
      <el-pagination v-model:current-page="page.no" :page-size="page.size" :total="page.total" layout="prev, pager, next" background @current-change="load" />
    </div>
  </div>
</template>

<style scoped>
.list { min-height: 200px; }
.row { display: block; border: 1px solid var(--line); border-radius: 8px; margin-bottom: 16px; overflow: hidden; }
.row:hover { border-color: var(--el-color-primary-light-5); }
.head { display: flex; justify-content: space-between; background: #fafafa; padding: 10px 16px; }
.status { color: var(--theme); }
.body { display: flex; align-items: center; }
.items { flex: 1; min-width: 0; padding: 4px 16px; }
.item { display: flex; align-items: center; gap: 12px; padding: 12px 0; }
.info { min-width: 0; }
.side { width: 220px; flex: none; text-align: right; padding: 16px; line-height: 1.9; }
</style>
