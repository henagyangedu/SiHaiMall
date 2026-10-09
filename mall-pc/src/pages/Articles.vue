<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getArticles, getHelp } from '@/api'

// 资讯 / 公告 / 帮助中心共用：?type=article|notice|help。帮助中心按分类分组显示，一次取完；另外两种分页
const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const TYPES = [{ key: 'article', label: 'article.articles' }, { key: 'notice', label: 'article.notices' }, { key: 'help', label: 'article.help' }]
const type = computed(() => (TYPES.some((x) => x.key === route.query.type) ? String(route.query.type) : 'article'))
const list = ref([])
const groups = ref([])
const page = reactive({ no: 1, size: 10, total: 0, loading: false })

async function load() {
  page.loading = true
  try {
    if (type.value === 'help') {
      groups.value = await getHelp()
    } else {
      const d = await getArticles({ type: type.value, page: page.no, size: page.size })
      list.value = d.list
      page.total = d.total
    }
  } finally {
    page.loading = false
  }
}

onMounted(load)
</script>

<template>
  <div class="panel">
    <el-tabs :model-value="type" @tab-change="(k) => router.push({ name: 'articles', query: { type: k } })">
      <el-tab-pane v-for="x in TYPES" :key="x.key" :label="t(x.label)" :name="x.key" />
    </el-tabs>

    <div v-loading="page.loading" class="body">
      <template v-if="type === 'help'">
        <div v-for="g in groups" :key="g.id" class="group">
          <h3>{{ g.name }}</h3>
          <router-link v-for="a in g.articles" :key="a.id" :to="{ name: 'article-detail', params: { id: a.id } }" class="help">{{ a.title }}</router-link>
        </div>
        <div v-if="!page.loading && !groups.length" class="empty">{{ t('common.empty') }}</div>
      </template>

      <template v-else>
        <router-link v-for="a in list" :key="a.id" :to="{ name: 'article-detail', params: { id: a.id } }" class="row">
          <img v-if="a.cover" :src="a.cover" alt="" class="cover" />
          <div class="info">
            <div class="title"><el-tag v-if="a.is_top" size="small" type="danger" effect="plain" class="top">TOP</el-tag>{{ a.title }}</div>
            <div v-if="a.summary" class="sub ellipsis-2">{{ a.summary }}</div>
            <div class="sub meta">{{ a.published_at.slice(0, 10) }}　{{ t('article.views', { n: a.views }) }}</div>
          </div>
        </router-link>
        <div v-if="!page.loading && !list.length" class="empty">{{ t('common.empty') }}</div>
        <div v-if="page.total > page.size" class="pager">
          <el-pagination v-model:current-page="page.no" :page-size="page.size" :total="page.total" layout="prev, pager, next" background @current-change="load" />
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.body { min-height: 240px; }
.row { display: flex; gap: 18px; padding: 18px 0; border-bottom: 1px solid var(--line); }
.row:hover .title { color: var(--theme); }
.cover { width: 180px; height: 110px; object-fit: cover; border-radius: 6px; flex: none; }
.info { flex: 1; min-width: 0; }
.title { font-size: 17px; font-weight: 600; margin-bottom: 8px; }
.top { margin-right: 8px; }
.meta { margin-top: 10px; }
.group { margin-bottom: 20px; }
.group h3 { margin: 12px 0 8px; font-size: 16px; }
.help { display: block; padding: 8px 0 8px 14px; border-bottom: 1px dashed var(--line); }
.help:hover { color: var(--theme); }
</style>
