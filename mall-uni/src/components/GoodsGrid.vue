<script setup>
import { useI18n } from 'vue-i18n'

// 两列商品网格。list：商品列表接口返回的 [{ id, title, cover, price, symbol, sales, sold_out }]
defineProps({ list: { type: Array, default: () => [] } })
const { t } = useI18n()
const open = (id) => uni.navigateTo({ url: `/pages/goods/detail?id=${id}` })
</script>

<template>
  <view class="grid">
    <view v-for="g in list" :key="g.id" class="item" @click="open(g.id)">
      <view class="cover">
        <image v-if="g.cover" :src="g.cover" mode="aspectFill" class="img" lazy-load />
        <view v-if="g.sold_out" class="mask">{{ t('home.soldOut') }}</view>
      </view>
      <view class="info">
        <view class="title ellipsis-2">{{ g.title }}</view>
        <view class="row">
          <text class="price">{{ g.symbol }}{{ g.price }}</text>
          <text class="sub">{{ t('home.sold', { n: g.sales }) }}</text>
        </view>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.grid { display: flex; flex-wrap: wrap; padding: 0 16rpx; }
.item { width: calc(50% - 16rpx); margin: 8rpx; background: #fff; border-radius: 16rpx; overflow: hidden; }
.cover { position: relative; width: 100%; padding-top: 100%; background: #f0f1f3; }
.img { position: absolute; inset: 0; width: 100%; height: 100%; }
.mask { position: absolute; inset: 0; background: rgba(0, 0, 0, 0.45); color: #fff; display: flex; align-items: center; justify-content: center; }
.info { padding: 16rpx; }
.title { height: 80rpx; line-height: 40rpx; font-size: 26rpx; }
.row { display: flex; justify-content: space-between; align-items: baseline; margin-top: 8rpx; }
.price { font-size: 32rpx; }
</style>
