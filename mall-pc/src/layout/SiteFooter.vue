<script setup>
import { useI18n } from 'vue-i18n'
import { state } from '@/store'

const { t } = useI18n()
const year = new Date().getFullYear()
</script>

<template>
  <footer>
    <div class="container links">
      <router-link :to="{ name: 'articles', query: { type: 'article' } }">{{ t('article.articles') }}</router-link>
      <router-link :to="{ name: 'articles', query: { type: 'notice' } }">{{ t('article.notices') }}</router-link>
      <router-link :to="{ name: 'articles', query: { type: 'help' } }">{{ t('article.help') }}</router-link>
      <router-link :to="{ name: 'protocols' }">{{ t('protocol.title') }}</router-link>
    </div>
    <div class="container inner">
      <!-- 版权信息、备案号在后台“基础信息”里配置；没配版权信息时显示站点名称 -->
      <span>{{ state.config.site?.copyright || `© ${year} ${state.config.site?.site_name || ''} ${t('pc.footer')}` }}</span>
      <span v-if="state.config.site?.site_icp" class="icp">{{ state.config.site.site_icp }}</span>
    </div>
  </footer>
</template>

<style scoped>
footer { background: #fff; border-top: 1px solid var(--line); }
.links { text-align: center; padding-top: 24px; }
.links a { margin: 0 18px; color: #606266; }
.links a:hover { color: var(--theme); }
.inner { padding: 16px 0 28px; text-align: center; color: var(--sub); font-size: 13px; }
.icp { margin-left: 16px; }
</style>
