<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getArticle } from '@/api'
import { safeHtml } from '@/utils/html'

const { t } = useI18n()
const route = useRoute()
const a = ref(null)
const failed = ref(false)

onMounted(async () => {
  try {
    a.value = await getArticle(route.params.id)
  } catch (e) {
    failed.value = true
  }
})
</script>

<template>
  <div v-if="a" class="panel page">
    <h1>{{ a.title }}</h1>
    <div class="sub meta">{{ a.published_at.slice(0, 10) }}<template v-if="a.author">　{{ a.author }}</template>　{{ t('article.views', { n: a.views }) }}</div>
    <!-- 正文是后台富文本编辑器保存的内容，后端不过滤，渲染前必须先清洗 -->
    <div class="content" v-html="safeHtml(a.content)" />
  </div>
  <div v-else-if="failed" class="panel empty">{{ t('article.offline') }}</div>
</template>

<style scoped>
.page { padding: 32px 48px 48px; }
h1 { font-size: 26px; line-height: 1.4; margin: 0; }
.meta { margin: 12px 0 24px; padding-bottom: 16px; border-bottom: 1px solid var(--line); }
.content { font-size: 15px; line-height: 1.9; word-break: break-word; overflow: hidden; }
.content :deep(img) { max-width: 100%; height: auto; }
</style>
