<script setup>
import { ref, reactive, watch } from 'vue'
import { onLoad, onPullDownRefresh, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { state } from '@/store'
import { getAds, getArticles, getCategories, getGoodsList, getHelp } from '@/api'
import { usePageTitle, nav } from '@/utils/page'
import { searchHistory, addSearchHistory, clearSearchHistory } from '@/utils/search'
import GoodsGrid from '@/components/GoodsGrid.vue'

// 首页（“温暖生活方式”版式，和 PC 端首页是同一套内容）：
// 搜索 → 主视觉轮播 → 公告 → 按场景逛（一级分类）→ 主题横幅 → 选购指南 → 帮助入口 → 推荐商品（上拉加载更多）。
// 每一块都取后台的真实数据，哪一块没有数据就整块不显示。
const { t } = useI18n()
usePageTitle(() => state.config.site?.site_name || t('tab.home'))

const banners = ref([])
const middle = ref(null)
const notice = ref(null)
const scenes = ref([])
const guides = ref([])
const helps = ref([])
const keyword = ref('')
// searched：当前列表对应的搜索词（空 = 没在搜索，显示首页）；panel：点了搜索框，正在看搜索历史和热门词
const searched = ref('')
const panel = ref(false)
const history = ref(searchHistory())
// 搜索没有结果时，在提示下面给出的推荐商品
const fallback = ref([])
const list = ref([])
const page = reactive({ no: 1, total: 0, loading: false })
const slide = ref(0)

const safe = (p, fallback) => p.catch(() => fallback)
async function loadTop() {
  const [ads, notices, cats, arts, help] = await Promise.all([
    safe(getAds('home_banner,home_middle'), {}), safe(getArticles({ type: 'notice', size: 1 }), { list: [] }),
    safe(getCategories(), []), safe(getArticles({ type: 'article', size: 3 }), { list: [] }), safe(getHelp(), []),
  ])
  banners.value = ads.home_banner || []
  middle.value = (ads.home_middle || [])[0] || null
  notice.value = notices.list[0] || null
  // 场景入口用一级分类，最多 6 个；配了图片的排在前面
  scenes.value = [...cats.filter((c) => c.image), ...cats.filter((c) => !c.image)].slice(0, 6)
  guides.value = arts.list
  helps.value = help.filter((c) => c.articles.length).slice(0, 3)
}
async function loadGoods(reset = false) {
  if (page.loading) return
  if (reset) { page.no = 1; list.value = [] }
  page.loading = true
  try {
    const d = await getGoodsList({ keyword: searched.value, page: page.no, size: 10 })
    list.value = reset ? d.list : list.value.concat(d.list)
    page.total = d.total
    page.no += 1
    if (reset) fallback.value = searched.value && !d.total ? (await safe(getGoodsList({ size: 6 }), { list: [] })).list : []
  } finally {
    page.loading = false
  }
}
const refresh = () => Promise.all([loadTop(), loadGoods(true)])

// 搜索：搜索框回车，或者点搜索历史、热门词
function doSearch(word) {
  const w = String(word ?? keyword.value).trim()
  keyword.value = w
  searched.value = w
  panel.value = false
  if (w) history.value = addSearchHistory(w)
  uni.hideKeyboard()
  loadGoods(true)
}
// 取消：回到首页
function cancelSearch() {
  const was = searched.value
  keyword.value = ''
  searched.value = ''
  panel.value = false
  uni.hideKeyboard()
  if (was) loadGoods(true)
}

function toCategory(id) {
  state.pendingCategoryId = Number(id)
  uni.switchTab({ url: '/pages/category/index' })
}
// 轮播图、横幅点击：按后台配置的跳转类型处理
function onBanner(b) {
  if (b.link_type === 'goods') uni.navigateTo({ url: `/pages/goods/detail?id=${b.link_value}` })
  else if (b.link_type === 'article') uni.navigateTo({ url: `/pages/article/detail?id=${b.link_value}` })
  else if (b.link_type === 'category') toCategory(b.link_value)
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
      <input v-model="keyword" class="input" :placeholder="t('home.search')" confirm-type="search" @focus="panel = true" @confirm="doSearch()" />
      <text v-if="panel || searched" class="search-cancel" @click="cancelSearch">{{ t('search.cancel') }}</text>
    </view>

    <!-- 点了搜索框：搜索历史和热门搜索 -->
    <view v-if="panel" class="search-panel">
      <template v-if="history.length">
        <view class="words-head"><text>{{ t('search.history') }}</text><text class="words-clear" @click="history = clearSearchHistory()">{{ t('search.clear') }}</text></view>
        <view class="words"><text v-for="w in history" :key="w" class="word" @click="doSearch(w)">{{ w }}</text></view>
      </template>
      <template v-if="(state.config.site?.hot_words || []).length">
        <view class="words-head"><text>{{ t('search.hot') }}</text></view>
        <view class="words"><text v-for="w in state.config.site.hot_words" :key="w" class="word hot" @click="doSearch(w)">{{ w }}</text></view>
      </template>
    </view>

    <!-- 搜索时只看结果，其余区块收起 -->
    <template v-else-if="!searched">
      <view v-if="banners.length" class="hero">
        <swiper class="hero-swiper" circular autoplay :interval="6000" @change="slide = $event.detail.current">
          <swiper-item v-for="b in banners" :key="b.id" @click="onBanner(b)">
            <view class="slide">
              <image :src="b.image" mode="aspectFill" class="slide-img" />
              <view class="slide-shade" />
              <view class="slide-body">
                <view class="eyebrow">{{ t('home.heroEyebrow') }}</view>
                <view class="hero-title">{{ b.title }}</view>
                <view class="hero-sub">{{ t('home.heroSub') }}</view>
                <view class="hero-btn">{{ t('home.heroCta') }}</view>
              </view>
            </view>
          </swiper-item>
        </swiper>
        <view v-if="banners.length > 1" class="dots">
          <view v-for="(b, i) in banners" :key="b.id" class="dot" :class="{ on: i === slide }" />
        </view>
      </view>

      <view v-if="notice" class="notice" @click="nav('/pages/article/list?type=notice')">
        <text class="tag">{{ t('home.notice') }}</text>
        <text class="ellipsis notice-text">{{ notice.title }}</text>
        <text class="arrow">›</text>
      </view>

      <template v-if="scenes.length">
        <view class="section">{{ t('home.sceneTitle') }}</view>
        <view class="scenes">
          <view v-for="c in scenes" :key="c.id" class="scene" @click="toCategory(c.id)">
            <view class="scene-img"><image v-if="c.image" :src="c.image" mode="aspectFill" class="fill" lazy-load /></view>
            <view class="scene-name ellipsis">{{ c.name }}</view>
          </view>
        </view>
      </template>

      <!-- 主题横幅：后台“首页中部”广告位的第一张 -->
      <view v-if="middle" class="band" @click="onBanner(middle)">
        <image :src="middle.image" mode="aspectFill" class="band-img" />
        <view class="band-body">
          <view class="band-title">{{ middle.title }}</view>
          <view class="band-sub">{{ t('home.bandSub') }}</view>
          <view class="band-btn">{{ t('home.bandCta') }}</view>
        </view>
      </view>

      <template v-if="guides.length">
        <view class="section row">
          <text>{{ t('home.guideTitle') }}</text>
          <text class="more" @click="nav('/pages/article/list?type=article')">{{ t('common.all') }} ›</text>
        </view>
        <view class="guides">
          <view v-for="a in guides" :key="a.id" class="guide" @click="nav(`/pages/article/detail?id=${a.id}`)">
            <view class="guide-img"><image v-if="a.cover" :src="a.cover" mode="aspectFill" class="fill" lazy-load /></view>
            <view class="guide-body">
              <view class="guide-title ellipsis-2">{{ a.title }}</view>
              <view class="guide-sum ellipsis">{{ a.summary }}</view>
              <view class="guide-more">{{ t('home.readGuide') }} ›</view>
            </view>
          </view>
        </view>
      </template>

      <view v-if="helps.length" class="help">
        <view class="help-lead" @click="nav('/pages/article/list?type=help')">{{ t('home.needHelp') }}</view>
        <view v-for="h in helps" :key="h.id" class="help-item ellipsis" @click="nav(`/pages/article/detail?id=${h.articles[0].id}`)">{{ h.name }}</view>
      </view>
    </template>

    <template v-if="!panel">
      <!-- 搜索没有结果：说明一下，并给几件推荐商品 -->
      <template v-if="searched && !page.loading && !list.length">
        <view class="no-result">
          <view class="no-result-title">{{ t('search.noResult', { keyword: searched }) }}</view>
          <view class="sub">{{ t('search.tryOther') }}</view>
        </view>
        <GoodsGrid :list="fallback" />
      </template>
      <template v-else>
        <view class="section">
          <view>{{ searched ? t('search.result', { keyword: searched }) : t('home.recommend') }}</view>
          <view v-if="!searched" class="section-sub">{{ t('home.recommendSub') }}</view>
        </view>
        <GoodsGrid :list="list" />
        <view class="sub foot">{{ page.loading ? t('common.loading') : (list.length ? t('common.noMore') : t('common.empty')) }}</view>
      </template>
    </template>
  </view>
</template>

<style lang="scss" scoped>
.search { padding: 16rpx 24rpx; display: flex; align-items: center; }
.search-cancel { flex: none; padding-left: 24rpx; font-size: 28rpx; color: var(--theme); }
.search-panel { padding: 8rpx 24rpx 40rpx; min-height: calc(100vh - 320rpx); }
.words-head { display: flex; justify-content: space-between; align-items: center; padding: 28rpx 0 16rpx; font-size: 28rpx; font-weight: 600; }
.words-clear { font-size: 24rpx; font-weight: 400; color: var(--sub); }
.words { display: flex; flex-wrap: wrap; gap: 16rpx; }
.word { max-width: 100%; padding: 0 28rpx; height: 60rpx; line-height: 60rpx; border-radius: 30rpx; background: var(--cream-deep); font-size: 26rpx; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.word.hot { background: #fff; border: 2rpx solid var(--line); }
.no-result { padding: 80rpx 48rpx 40rpx; text-align: center; }
.no-result-title { margin-bottom: 12rpx; font-size: 30rpx; font-weight: 600; }
.input { flex: 1; min-width: 0; background: var(--cream-deep); border-radius: 40rpx; height: 76rpx; padding: 0 32rpx; font-size: 28rpx; }
.fill { width: 100%; height: 100%; display: block; }

/* 主视觉：标题压在轮播图左侧 */
.hero { position: relative; margin: 8rpx 24rpx 0; border-radius: 24rpx; overflow: hidden; }
.hero-swiper { height: 400rpx; }
.slide { position: relative; height: 100%; background: var(--cream-deep); }
/* 图片靠右对齐再裁切：主体在右侧，被裁掉的是左边让文字盖住的那一段 */
.slide-img { position: absolute; top: 0; right: 0; width: 100%; height: 100%; }
.slide-shade { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(90deg, rgba(240, 233, 221, 0.96) 0%, rgba(240, 233, 221, 0.82) 42%, rgba(240, 233, 221, 0) 78%); }
.slide-body { position: relative; height: 100%; padding: 0 36rpx; display: flex; flex-direction: column; justify-content: center; align-items: flex-start; }
.eyebrow { font-size: 20rpx; letter-spacing: 6rpx; opacity: 0.7; }
.hero-title { margin: 14rpx 0 10rpx; max-width: 460rpx; font-size: 40rpx; line-height: 1.28; font-weight: 700; }
.hero-sub { max-width: 400rpx; font-size: 24rpx; opacity: 0.8; }
.hero-btn { margin-top: 24rpx; padding: 0 36rpx; height: 64rpx; line-height: 64rpx; border-radius: 32rpx; background: var(--theme); color: #fff; font-size: 26rpx; }
.dots { position: absolute; left: 36rpx; bottom: 22rpx; display: flex; gap: 10rpx; }
.dot { width: 12rpx; height: 12rpx; border-radius: 6rpx; background: rgba(41, 43, 39, 0.2); transition: width 0.2s; }
.dot.on { width: 32rpx; background: var(--theme); }

.notice { display: flex; align-items: center; background: var(--cream-deep); margin: 20rpx 24rpx 0; padding: 0 24rpx; height: 76rpx; border-radius: 24rpx; }
.tag { color: var(--theme); font-weight: 600; font-size: 24rpx; margin-right: 16rpx; flex: none; }
.notice-text { flex: 1; font-size: 26rpx; }
.arrow { color: var(--sub); margin-left: 12rpx; }

.section { padding: 40rpx 24rpx 16rpx; font-size: 34rpx; font-weight: 700; }
.section.row { display: flex; justify-content: space-between; align-items: baseline; }
.section-sub { margin-top: 6rpx; font-size: 24rpx; font-weight: 400; color: var(--sub); }
.more { font-size: 26rpx; font-weight: 400; color: var(--theme); }

/* 场景入口：三列两行 */
.scenes { display: flex; flex-wrap: wrap; padding: 0 16rpx; }
.scene { width: calc(33.333% - 16rpx); margin: 8rpx; background: #fff; border-radius: 24rpx; overflow: hidden; }
.scene-img { height: 150rpx; background: var(--cream-deep); }
.scene-name { padding: 14rpx 8rpx; text-align: center; font-size: 24rpx; }

/* 主题横幅 */
.band { position: relative; margin: 32rpx 24rpx 0; height: 220rpx; border-radius: 24rpx; overflow: hidden; background: var(--sage); }
.band-img { position: absolute; top: 0; right: 0; width: 60%; height: 100%; opacity: 0.9; }
.band-body { position: relative; height: 100%; padding: 0 36rpx; display: flex; flex-direction: column; justify-content: center; align-items: flex-start; }
.band-title { max-width: 440rpx; font-size: 34rpx; font-weight: 700; color: #fff; line-height: 1.3; }
.band-sub { margin: 8rpx 0 18rpx; font-size: 24rpx; color: rgba(255, 255, 255, 0.9); }
.band-btn { padding: 0 28rpx; height: 52rpx; line-height: 52rpx; border-radius: 26rpx; background: var(--theme); color: #fff; font-size: 24rpx; }

/* 选购指南 */
.guides { padding: 0 24rpx; }
.guide { display: flex; background: #fff; border-radius: 24rpx; overflow: hidden; margin-bottom: 16rpx; }
.guide-img { width: 220rpx; height: 180rpx; flex: none; background: var(--cream-deep); }
.guide-body { flex: 1; min-width: 0; padding: 20rpx 24rpx; display: flex; flex-direction: column; }
.guide-title { font-size: 28rpx; font-weight: 600; line-height: 1.4; }
.guide-sum { margin-top: 8rpx; font-size: 24rpx; color: var(--sub); }
.guide-more { margin-top: auto; font-size: 24rpx; color: var(--theme); }

/* 帮助入口 */
.help { display: flex; align-items: center; margin: 8rpx 24rpx 0; height: 84rpx; background: var(--cream-deep); border-radius: 24rpx; font-size: 24rpx; }
.help-lead { padding: 0 24rpx; font-weight: 600; color: var(--theme); flex: none; }
.help-item { flex: 1; min-width: 0; text-align: center; padding: 0 12rpx; border-left: 2rpx solid var(--line); }

.foot { text-align: center; padding: 30rpx 0 40rpx; }
</style>
