<script setup>
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getProtocols, getProtocol } from '@/api'
import { nav } from '@/utils/page'

const { t } = useI18n()
// 不带 key：列出后台已填写的协议；带 key：显示这一份协议的正文
const key = ref('')
const list = ref([])
const doc = ref(null)
const loaded = ref(false)

onLoad(async (q) => {
  key.value = q.key || ''
  try {
    if (key.value) {
      doc.value = await getProtocol(key.value)
      uni.setNavigationBarTitle({ title: doc.value.title })
    } else {
      uni.setNavigationBarTitle({ title: t('protocol.title') })
      list.value = await getProtocols()
    }
  } finally {
    loaded.value = true
  }
})
</script>

<template>
  <view v-if="key" class="page">
    <template v-if="doc">
      <rich-text v-if="doc.content" :nodes="doc.content" class="content" />
      <view v-else class="empty">{{ t('common.empty') }}</view>
    </template>
  </view>
  <view v-else>
    <view v-if="list.length" class="card list">
      <view v-for="p in list" :key="p.key" class="cell" @click="nav(`/pages/protocol/index?key=${p.key}`)"><text>{{ p.title }}</text><text class="sub">›</text></view>
    </view>
    <view v-else-if="loaded" class="empty">{{ t('common.empty') }}</view>
  </view>
</template>

<style lang="scss" scoped>
.page { background: #fff; min-height: 100vh; padding: 32rpx; box-sizing: border-box; }
.content { font-size: 30rpx; line-height: 1.8; word-break: break-word; }
.list { padding: 0 24rpx; }
.cell { display: flex; justify-content: space-between; align-items: center; height: 100rpx; border-bottom: 2rpx solid var(--line); }
.cell:last-child { border-bottom: none; }
</style>
