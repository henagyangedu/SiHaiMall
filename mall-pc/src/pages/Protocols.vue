<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getProtocols, getProtocol } from '@/api'
import { safeHtml } from '@/utils/html'

// 左侧列出后台已填写的协议，右侧显示选中那一份的正文；不带 key 时显示第一份
const { t } = useI18n()
const route = useRoute()
const list = ref([])
const doc = ref(null)
const loaded = ref(false)
const key = computed(() => String(route.params.key || list.value[0]?.key || ''))

onMounted(async () => {
  try {
    list.value = await getProtocols()
    if (key.value) doc.value = await getProtocol(key.value)
  } catch (e) {
    doc.value = null
  } finally {
    loaded.value = true
  }
})
</script>

<template>
  <div class="wrap">
    <aside class="panel menu">
      <div class="menu-title">{{ t('protocol.title') }}</div>
      <router-link v-for="p in list" :key="p.key" :to="{ name: 'protocols', params: { key: p.key } }" class="item" :class="{ on: p.key === key }">{{ p.title }}</router-link>
    </aside>
    <section class="panel content">
      <template v-if="doc">
        <h1>{{ doc.title }}</h1>
        <!-- 正文是后台富文本编辑器保存的内容，后端不过滤，渲染前必须先清洗 -->
        <div v-if="doc.content" class="body" v-html="safeHtml(doc.content)" />
        <div v-else class="empty">{{ t('common.empty') }}</div>
      </template>
      <div v-else-if="loaded" class="empty">{{ t('common.empty') }}</div>
    </section>
  </div>
</template>

<style scoped>
.wrap { display: flex; gap: 16px; align-items: flex-start; }
.menu { width: 220px; flex: none; padding: 12px 0; }
.menu-title { font-weight: 600; padding: 6px 20px 10px; }
.item { display: block; padding: 10px 20px; }
.item:hover, .item.on { color: var(--theme); }
.item.on { background: #fcebeb; font-weight: 600; }
.content { flex: 1; min-width: 0; margin-top: 0 !important; padding: 28px 40px 40px; min-height: 320px; }
h1 { font-size: 24px; margin: 0 0 20px; }
.body { font-size: 15px; line-height: 1.9; word-break: break-word; overflow: hidden; }
.body :deep(img) { max-width: 100%; height: auto; }
</style>
