<script setup>
import { watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import SiteHeader from '@/layout/SiteHeader.vue'
import SiteFooter from '@/layout/SiteFooter.vue'

const { t } = useI18n()
const route = useRoute()

// 浏览器标题：站点名称（后台“基础信息”里配置的，随语言变化）
watch(() => [state.config.site?.site_name, state.lang], () => {
  document.title = state.config.site?.site_name || t('tab.home')
}, { immediate: true })

// 站点图标：后台“基础信息”里配置的；没配就保持空图标
watch(() => state.config.site?.site_favicon, (icon) => {
  const link = document.querySelector('link[rel="icon"]')
  if (link && icon) link.href = icon
}, { immediate: true })
</script>

<template>
  <SiteHeader />
  <main :class="route.meta.wide ? 'main wide' : 'container main'">
    <!-- 切换语言或币种时整页重建，商品名称和价格都重新取 -->
    <router-view :key="`${route.fullPath}|${state.lang}|${state.currency}`" />
  </main>
  <SiteFooter />
</template>

<style scoped>
.main { padding: 24px 0 48px; min-height: calc(100vh - 220px); }
.main.wide { padding-top: 0; }
</style>
