<script setup>
import { ref, reactive, watch } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getAds, getArticles, getGoodsList } from '@/api'
import { usePageTitle, nav } from '@/utils/page'
import GoodsGrid from '@/components/GoodsGrid.vue'

const { t } = useI18n()
usePageTitle(() => state.config.site?.site_name || t('tab.home'))

const banners = ref([])
const notice = ref(null)
const keyword = ref('')
const list = ref([])
const page = reactive({ no: 1, total: 0, loading: false })

async function loadTop() {
  try { banners.value = (await getAds('home_banner')).home_banner || [] } catch (e) { banners.value = [] }
  try { notice.value = (await getArticles({ type: 'notice', size: 1 })).list[0] || null } catch (e) { notice.value = null }
}
async function loadGoods(reset = false) {
  if (page.loading) return
  if (reset) { page.no = 1; list.value = [] }
  page.loading = true
  try {
    const d = await getGoodsList({ keyword: keyword.value, page: page.no, size: 10 })
    list.value = reset ? d.list : list.value.concat(d.list)
    page.total = d.total
    page.no += 1
  } finally {
    page.loading = false
  }
}
const refresh = () => Promise.all([loadTop(), loadGoods(true)])

// 轮播图点击：按后台配置的跳转类型处理
function onBanner(b) {
  if (b.link_type === 'goods') uni.navigateTo({ url: `/pages/goods/detail?id=${b.link_value}` })
  else if (b.link_type === 'article') uni.navigateTo({ url: `/pages/article/detail?id=${b.link_value}` })
  else if (b.link_type === 'category') { state.pendingCategoryId = Number(b.link_value); uni.switchTab({ url: '/pages/category/index' }) }
  else if (b.link_type === 'url') {
    // #ifdef H5
    window.location.href = b.link_value
    // #endif
  }
}

onLoad(refresh)
onPullDownRefresh(() => refresh().finally(() => uni.stopPullDownRefresh()))
onReachBottom(() => { if (list.value.length < page.total) loadGoods() })
// 切换语言或币种后，商品名称和价格要重新取
watch(() => [state.lang, state.currency], refresh)
</script>

<template>
  <view>
    <view class="search">
      <input v-model="keyword" class="input" :placeholder="t('home.search')" confirm-type="search" @confirm="loadGoods(true)" />
    </view>

    <swiper v-if="banners.length" class="banner" circular autoplay indicator-dots indicator-active-color="#e4393c">
      <swiper-item v-for="b in banners" :key="b.id" @click="onBanner(b)">
        <image :src="b.image" mode="aspectFill" class="banner-img" />
      </swiper-item>
    </swiper>

    <view v-if="notice" class="notice" @click="nav('/pages/article/list?type=notice')">
      <text class="tag">{{ t('home.notice') }}</text>
      <text class="ellipsis" style="flex: 1">{{ notice.title }}</text>
    </view>

    <view class="section">{{ t('home.recommend') }}</view>
    <GoodsGrid :list="list" />
    <view class="sub foot">{{ page.loading ? t('common.loading') : (list.length ? t('common.noMore') : t('common.empty')) }}</view>
  </view>
</template>

<style lang="scss" scoped>
.search { background: #fff; padding: 16rpx 24rpx; }
.input { background: #f5f6f8; border-radius: 36rpx; height: 72rpx; padding: 0 28rpx; font-size: 28rpx; }
.banner { height: 320rpx; margin: 20rpx 24rpx 0; border-radius: 16rpx; overflow: hidden; }
.banner-img { width: 100%; height: 100%; }
.notice { display: flex; align-items: center; background: #fff; margin: 20rpx 24rpx 0; padding: 18rpx 24rpx; border-radius: 16rpx; }
.tag { color: var(--theme); border: 2rpx solid var(--theme); border-radius: 8rpx; padding: 0 10rpx; font-size: 22rpx; margin-right: 16rpx; }
.section { padding: 28rpx 24rpx 12rpx; font-size: 32rpx; font-weight: 600; }
.foot { text-align: center; padding: 30rpx 0 40rpx; }
</style>
