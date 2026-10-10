<script setup>
import { useI18n } from 'vue-i18n'
import { state } from '@/store'

// 站点标识：页头和页脚共用。后台“基础信息”配了 Logo 图片就用图片；没配就用爪印图标 + 站点名称 + 一句话简介。
defineProps({ small: { type: Boolean, default: false } })
const { t } = useI18n()
</script>

<template>
  <router-link :to="{ name: 'home' }" class="logo" :class="{ small }">
    <img v-if="state.config.site?.site_logo" :src="state.config.site.site_logo" alt="" class="logo-img" />
    <template v-else>
      <svg class="paw" viewBox="0 0 48 48" aria-hidden="true">
        <ellipse cx="13" cy="19" rx="5" ry="6.5" transform="rotate(-18 13 19)" />
        <ellipse cx="23" cy="12" rx="5" ry="6.8" transform="rotate(-4 23 12)" />
        <ellipse cx="34" cy="14.5" rx="5" ry="6.6" transform="rotate(14 34 14.5)" />
        <ellipse cx="41" cy="24.5" rx="4.5" ry="6" transform="rotate(30 41 24.5)" />
        <path d="M26 24c-7 0-13.5 6-13.5 12.2 0 4 3 6.3 6.6 6.3 2.6 0 4.4-1.2 6.9-1.2s4.300 1.2 6.900 1.2c3.600 0 6.600-2.300 6.600-6.300C39.500 30 33 24 26 24z" />
      </svg>
      <span class="words">
        <span class="name">{{ state.config.site?.site_name || t('tab.home') }}</span>
        <span v-if="state.config.site?.site_desc" class="slogan">{{ state.config.site.site_desc }}</span>
      </span>
    </template>
  </router-link>
</template>

<style scoped>
.logo { display: flex; align-items: center; gap: 12px; flex: none; color: var(--text); }
.logo-img { max-height: 52px; display: block; }
.paw { width: 44px; height: 44px; fill: var(--theme); flex: none; }
.words { display: flex; flex-direction: column; }
.name { font-size: 22px; font-weight: 700; letter-spacing: 0.08em; line-height: 1.2; }
.slogan { margin-top: 4px; font-size: 11px; letter-spacing: 0.22em; color: var(--sub); white-space: nowrap; }
.small .paw { width: 36px; height: 36px; }
.small .name { font-size: 18px; }
.small .logo-img { max-height: 40px; }
</style>
