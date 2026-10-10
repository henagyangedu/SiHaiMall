<script setup>
import { useI18n } from 'vue-i18n'

// 两列商品网格。list：商品列表接口返回的 [{ id, title, subtitle, cover, price, symbol, sales, sold_out, multi_spec }]
defineProps({ list: { type: Array, default: () => [] } })
const { t } = useI18n()
const open = (id) => uni.navigateTo({ url: `/pages/goods/detail?id=${id}` })
</script>

<template>
  <view class="grid">
    <view v-for="g in list" :key="g.id" class="item" @click="open(g.id)">
      <view class="cover">
        <image v-if="g.cover" :src="g.cover" mode="aspectFill" class="img" lazy-load />
        <text v-if="g.multi_spec" class="tag">{{ t('home.multiSpec') }}</text>
        <view v-if="g.sold_out" class="mask">{{ t('home.soldOut') }}</view>
      </view>
      <view class="info">
        <view class="title ellipsis">{{ g.title }}</view>
        <view class="desc ellipsis">{{ g.subtitle }}</view>
        <view class="row">
          <view>
            <!-- 多规格商品显示的是最低价 -->
            <text class="price">{{ g.symbol }}{{ g.price }}</text><text v-if="g.multi_spec" class="from">{{ t('home.priceFrom') }}</text>
            <view class="sub sold">{{ t('home.sold', { n: g.sales }) }}</view>
          </view>
          <view class="add">＋</view>
        </view>
      </view>
    </view>
  </view>
</template>

<style lang="scss" scoped>
.grid { display: flex; flex-wrap: wrap; padding: 0 16rpx; }
.item { width: calc(50% - 16rpx); margin: 8rpx; background: #fff; border-radius: 24rpx; overflow: hidden; }
.cover { position: relative; width: 100%; padding-top: 86%; background: var(--cream-deep); }
.img { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
.tag { position: absolute; left: 16rpx; top: 16rpx; padding: 4rpx 16rpx; border-radius: 20rpx; background: rgba(41, 43, 39, 0.55); color: #fff; font-size: 20rpx; }
.mask { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(41, 43, 39, 0.45); color: #fff; display: flex; align-items: center; justify-content: center; }
.info { padding: 20rpx 20rpx 22rpx; }
.title { font-size: 28rpx; font-weight: 600; }
.desc { margin-top: 6rpx; min-height: 34rpx; font-size: 22rpx; color: var(--sub); }
.row { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 14rpx; }
.price { font-size: 34rpx; }
.from { margin-left: 6rpx; font-size: 22rpx; color: var(--theme); }
.sold { margin-top: 4rpx; font-size: 22rpx; }
.add { width: 56rpx; height: 56rpx; line-height: 52rpx; text-align: center; border-radius: 50%; background: var(--theme); color: #fff; font-size: 32rpx; flex: none; }
</style>
