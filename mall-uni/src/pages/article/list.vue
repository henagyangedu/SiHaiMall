<script setup>
import { ref, reactive } from 'vue'
import { onLoad, onReachBottom } from '@dcloudio/uni-app'
import { useI18n } from 'vue-i18n'
import { getArticles, getHelp } from '@/api'
import { usePageTitle, nav } from '@/utils/page'

const { t } = useI18n()
// type：article 资讯 / notice 公告 / help 帮助中心（帮助按分类分组显示，一次取完）
const type = ref('article')
usePageTitle(() => t({ article: 'article.articles', notice: 'article.notices', help: 'article.help' }[type.value]))

const list = ref([])
const groups = ref([])
const page = reactive({ no: 1, total: 0, loading: false, loaded: false })

async function load() {
  if (page.loading) return
  page.loading = true
  try {
    if (type.value === 'help') {
      groups.value = await getHelp()
    } else {
      const d = await getArticles({ type: type.value, page: page.no, size: 10 })
      list.value = list.value.concat(d.list)
      page.total = d.total
      page.no += 1
    }
  } finally {
    page.loading = false
    page.loaded = true
  }
}
const open = (id) => nav(`/pages/article/detail?id=${id}`)

onLoad((q) => {
  if (['article', 'notice', 'help'].includes(q.type)) type.value = q.type
  load()
})
onReachBottom(() => { if (type.value !== 'help' && list.value.length < page.total) load() })
</script>

<template>
  <view>
    <template v-if="type === 'help'">
      <view v-for="g in groups" :key="g.id" class="card group">
        <view class="gname">{{ g.name }}</view>
        <view v-for="a in g.articles" :key="a.id" class="cell" @click="open(a.id)"><text class="ellipsis">{{ a.title }}</text><text class="sub">›</text></view>
      </view>
      <view v-if="page.loaded && !groups.length" class="empty">{{ t('common.empty') }}</view>
    </template>

    <template v-else>
      <view v-for="a in list" :key="a.id" class="card row" @click="open(a.id)">
        <view class="body">
          <view class="title ellipsis-2"><text v-if="a.is_top" class="top">TOP</text>{{ a.title }}</view>
          <view v-if="a.summary" class="sub ellipsis-2">{{ a.summary }}</view>
          <view class="sub meta">{{ a.published_at.slice(0, 10) }}　{{ t('article.views', { n: a.views }) }}</view>
        </view>
        <image v-if="a.cover" :src="a.cover" mode="aspectFill" class="cover" />
      </view>
      <view class="sub end">{{ page.loading ? t('common.loading') : (list.length ? t('common.noMore') : t('common.empty')) }}</view>
    </template>
  </view>
</template>

<style lang="scss" scoped>
.group { padding: 0 24rpx; }
.gname { font-weight: 600; padding: 24rpx 0 8rpx; }
.cell { display: flex; justify-content: space-between; align-items: center; height: 92rpx; border-top: 2rpx solid var(--line); gap: 20rpx; }
.row { display: flex; gap: 20rpx; }
.body { flex: 1; min-width: 0; }
.title { font-size: 30rpx; font-weight: 600; margin-bottom: 8rpx; }
.top { color: var(--theme); border: 2rpx solid var(--theme); border-radius: 6rpx; font-size: 20rpx; padding: 0 8rpx; margin-right: 10rpx; font-weight: 400; }
.meta { margin-top: 8rpx; }
.cover { width: 200rpx; height: 140rpx; border-radius: 12rpx; background: #f0f1f3; flex: none; }
.end { text-align: center; padding: 30rpx 0 40rpx; }
</style>
