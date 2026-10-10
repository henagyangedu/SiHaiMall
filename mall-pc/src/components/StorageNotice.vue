<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'

// 本地存储告知条：后台“基础信息”里开启后，首次访问在页面底部显示一次，点“知道了”后不再出现。
// 本站不用 Cookie、不接统计和广告脚本，只在浏览器里存登录状态、语言、币种这些必要的东西，所以只是告知，不需要“接受 / 拒绝”。
const KEY = 'gm_storage_notice'
const { t } = useI18n()
const read = () => { try { return localStorage.getItem(KEY) === '1' } catch (e) { return false } }
const closed = ref(read())
const show = computed(() => !closed.value && Number(state.config.site?.storage_notice_enabled) === 1)

function close() {
  closed.value = true
  try { localStorage.setItem(KEY, '1') } catch (e) { /* 存不了就只在这次访问里不再显示 */ }
}
</script>

<template>
  <div v-if="show" class="storage-notice" role="region" :aria-label="t('privacy.noticeOk')">
    <div class="container inner">
      <span class="text">{{ t('privacy.noticeText') }}
        <router-link :to="{ name: 'protocols', params: { key: 'privacy_policy' } }" class="link">{{ t('privacy.noticeLink') }}</router-link>
      </span>
      <el-button type="primary" size="small" @click="close">{{ t('privacy.noticeOk') }}</el-button>
    </div>
  </div>
</template>

<style scoped>
.storage-notice { position: fixed; left: 0; right: 0; bottom: 0; z-index: 2000; background: #fff; border-top: 1px solid var(--line); box-shadow: 0 -4px 16px rgba(41, 43, 39, 0.06); }
.inner { display: flex; align-items: center; gap: 20px; padding: 14px 0; }
.text { flex: 1; font-size: 13px; line-height: 1.6; color: var(--text); }
.link { color: var(--theme); margin-left: 6px; }
</style>
