<script setup>
import { ref } from 'vue'
import { onLoad } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getArticle } from '@/api'

const { t } = useI18n()
const a = ref(null)
const failed = ref(false)

onLoad(async (q) => {
  try {
    a.value = await getArticle(Number(q.id || 0))
    uni.setNavigationBarTitle({ title: a.value.title })
  } catch (e) {
    failed.value = true
  }
})
</script>

<template>
  <view v-if="a" class="page">
    <view class="title">{{ a.title }}</view>
    <view class="sub meta">{{ a.published_at.slice(0, 10) }}<text v-if="a.author">　{{ a.author }}</text>　{{ t('article.views', { n: a.views }) }}</view>
    <rich-text :nodes="a.content" class="content" />
  </view>
  <view v-else-if="failed" class="empty">{{ t('article.offline') }}</view>
</template>

<style lang="scss" scoped>
.page { background: #fff; min-height: 100vh; padding: 32rpx; box-sizing: border-box; }
.title { font-size: 40rpx; font-weight: 600; line-height: 1.4; }
.meta { margin: 16rpx 0 28rpx; }
.content { font-size: 30rpx; line-height: 1.8; word-break: break-word; }
</style>
