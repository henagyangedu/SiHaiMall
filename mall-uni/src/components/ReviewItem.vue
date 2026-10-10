<script setup>
import { useI18n } from 'vue-i18n'

// 一条商品评价：商品详情页和评价列表页共用
const props = defineProps({ review: { type: Object, required: true } })
const { t } = useI18n()

function preview(i) {
  uni.previewImage({ urls: props.review.images, current: i })
}
</script>

<template>
  <view class="rv">
    <view class="who">
      <image v-if="review.avatar" :src="review.avatar" mode="aspectFill" class="avatar" />
      <view v-else class="avatar ph">{{ (review.nickname || '?').slice(0, 1) }}</view>
      <view class="name">{{ review.nickname }}</view>
      <view class="stars"><text v-for="n in 5" :key="n" :class="{ on: n <= review.rating }">★</text></view>
    </view>
    <view v-if="review.content" class="content">{{ review.content }}</view>
    <view v-if="review.images.length" class="pics">
      <image v-for="(u, i) in review.images" :key="i" :src="u" mode="aspectFill" class="pic" @click="preview(i)" />
    </view>
    <view class="sub meta">{{ review.created_at }}<text v-if="review.spec_text">　{{ review.spec_text }}</text></view>
    <view v-if="review.reply" class="reply"><text class="reply-by">{{ t('review.reply') }}</text>{{ review.reply }}</view>
  </view>
</template>

<style lang="scss" scoped>
.rv { padding: 24rpx 0; border-bottom: 2rpx solid var(--line); }
.rv:last-child { border-bottom: none; }
.who { display: flex; align-items: center; }
.avatar { width: 56rpx; height: 56rpx; border-radius: 50%; background: #efe8dc; flex: none; }
.ph { text-align: center; line-height: 56rpx; font-size: 26rpx; color: #8b877d; }
.name { flex: 1; margin-left: 16rpx; font-size: 26rpx; }
.stars { color: #ded6c8; font-size: 26rpx; letter-spacing: 2rpx; }
.stars .on { color: #f7ba2a; }
.content { margin-top: 16rpx; line-height: 1.6; word-break: break-all; }
.pics { display: flex; flex-wrap: wrap; gap: 12rpx; margin-top: 16rpx; }
.pic { width: 160rpx; height: 160rpx; border-radius: 12rpx; }
.meta { margin-top: 12rpx; }
.reply { margin-top: 16rpx; padding: 16rpx 20rpx; background: #f3ede2; border-radius: 12rpx; font-size: 26rpx; line-height: 1.6; }
.reply-by { color: var(--theme); margin-right: 12rpx; }
</style>
