<script setup>
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import SiteLogo from '@/components/SiteLogo.vue'

const { t } = useI18n()
const year = new Date().getFullYear()
</script>

<template>
  <footer>
    <div class="container inner">
      <SiteLogo small />
      <div class="links">
        <router-link :to="{ name: 'articles', query: { type: 'article' } }">{{ t('article.articles') }}</router-link>
        <router-link :to="{ name: 'articles', query: { type: 'notice' } }">{{ t('article.notices') }}</router-link>
        <router-link :to="{ name: 'articles', query: { type: 'help' } }">{{ t('article.help') }}</router-link>
        <router-link :to="{ name: 'protocols' }">{{ t('protocol.title') }}</router-link>
      </div>
      <div class="copy">
        <!-- 版权信息、备案号在后台“基础信息”里配置；没配版权信息时显示站点名称 -->
        <span>{{ state.config.site?.copyright || `© ${year} ${state.config.site?.site_name || ''} ${t('pc.footer')}` }}</span>
        <span v-if="state.config.site?.site_icp" class="icp">{{ state.config.site.site_icp }}</span>
      </div>
    </div>
  </footer>
</template>

<style scoped>
footer { margin-top: 8px; border-top: 1px solid var(--line); background: var(--bg); }
.inner { height: 96px; display: flex; align-items: center; }
.links { flex: 1; display: flex; justify-content: center; gap: 44px; }
.links a { color: var(--text); opacity: 0.8; }
.links a:hover { color: var(--theme); opacity: 1; }
.copy { color: var(--sub); font-size: 13px; text-align: right; }
.icp { margin-left: 16px; }
</style>
