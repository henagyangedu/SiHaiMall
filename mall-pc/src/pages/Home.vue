<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getAds, getArticles, getCategories, getGoodsList } from '@/api'
import GoodsCard from '@/components/GoodsCard.vue'

const { t } = useI18n()
const router = useRouter()

const banners = ref([])
const notice = ref(null)
const categories = ref([])
const list = ref([])
const page = reactive({ no: 1, size: 20, total: 0, loading: false })

async function loadTop() {
  getAds('home_banner').then((d) => { banners.value = d.home_banner || [] }).catch(() => {})
  getArticles({ type: 'notice', size: 1 }).then((d) => { notice.value = d.list[0] || null }).catch(() => {})
  getCategories().then((d) => { categories.value = d }).catch(() => {})
}
async function loadGoods() {
  page.loading = true
  try {
    const d = await getGoodsList({ page: page.no, size: page.size })
    list.value = d.list
    page.total = d.total
  } finally {
    page.loading = false
  }
}

// 轮播图点击：按后台配置的跳转类型处理
function onBanner(b) {
  if (b.link_type === 'goods') router.push({ name: 'goods-detail', params: { id: b.link_value } })
  else if (b.link_type === 'category') router.push({ name: 'goods', query: { category_id: b.link_value } })
  else if (b.link_type === 'article') router.push({ name: 'article-detail', params: { id: b.link_value } })
  else if (b.link_type === 'url' && /^https?:\/\//i.test(b.link_value)) window.open(b.link_value, '_blank', 'noopener')
}

onMounted(() => {
  loadTop()
  loadGoods()
})
</script>

<template>
  <div class="top">
    <aside class="panel cats">
      <div class="cat-title">{{ t('pc.allCategories') }}</div>
      <template v-for="c in categories" :key="c.id">
        <router-link :to="{ name: 'goods', query: { category_id: c.id } }" class="cat">{{ c.name }}</router-link>
        <router-link v-for="s in c.children" :key="s.id" :to="{ name: 'goods', query: { category_id: s.id } }" class="cat child">{{ s.name }}</router-link>
      </template>
    </aside>

    <div class="hero">
      <el-carousel v-if="banners.length" height="380px" :interval="5000">
        <el-carousel-item v-for="b in banners" :key="b.id">
          <img :src="b.image" :alt="b.title || ''" class="banner" :class="{ click: b.link_type !== 'none' }" @click="onBanner(b)" />
        </el-carousel-item>
      </el-carousel>
      <div v-else class="banner-ph" />
      <router-link v-if="notice" :to="{ name: 'article-detail', params: { id: notice.id } }" class="notice">
        <span class="tag">{{ t('home.notice') }}</span><span class="ellipsis">{{ notice.title }}</span>
      </router-link>
    </div>
  </div>

  <h2 class="heading">{{ t('home.recommend') }}</h2>
  <div v-loading="page.loading" class="grid">
    <GoodsCard v-for="g in list" :key="g.id" :g="g" />
  </div>
  <div v-if="!page.loading && !list.length" class="empty">{{ t('common.empty') }}</div>
  <div v-if="page.total > page.size" class="pager">
    <el-pagination v-model:current-page="page.no" :page-size="page.size" :total="page.total" layout="prev, pager, next" background @current-change="loadGoods" />
  </div>
</template>

<style scoped>
.top { display: flex; gap: 16px; }
.cats { width: 220px; flex: none; padding: 12px 0; max-height: 428px; overflow-y: auto; }
.cat-title { font-weight: 600; padding: 6px 20px 10px; }
.cat { display: block; padding: 8px 20px; }
.cat.child { padding-left: 36px; color: #606266; font-size: 13px; }
.cat:hover { color: var(--theme); background: #fcebeb; }
.hero { flex: 1; min-width: 0; }
.banner { width: 100%; height: 380px; object-fit: cover; border-radius: 8px; display: block; }
.banner.click { cursor: pointer; }
.banner-ph { height: 380px; border-radius: 8px; background: linear-gradient(135deg, #fcebeb, #f5f6f8); }
.notice { display: flex; align-items: center; background: #fff; border-radius: 8px; padding: 10px 16px; margin-top: 8px; }
.tag { color: var(--theme); border: 1px solid var(--theme); border-radius: 4px; padding: 0 6px; font-size: 12px; margin-right: 10px; flex: none; }
.heading { font-size: 20px; margin: 28px 0 16px; }
.grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; min-height: 120px; }
</style>
