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
</script>

<template>
  <SiteHeader />
  <main class="container main">
    <!-- 切换语言或币种时整页重建，商品名称和价格都重新取 -->
    <router-view :key="`${route.fullPath}|${state.lang}|${state.currency}`" />
  </main>
  <SiteFooter />
</template>

<style scoped>
.main { padding: 20px 0 40px; min-height: calc(100vh - 220px); }
</style>
