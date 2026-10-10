<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowRight, Bell, QuestionFilled } from '@element-plus/icons-vue'
import { getAds, getArticles, getCategories, getGoodsList, getHelp } from '@/api'
import GoodsCard from '@/components/GoodsCard.vue'

// 首页（“温暖生活方式”版式）：主视觉 → 公告 → 按场景逛（一级分类）→ 推荐商品 → 主题横幅 → 选购指南 → 帮助入口。
// 每一块都取后台的真实数据，哪一块没有数据就整块不显示。
const { t } = useI18n()
const router = useRouter()

const banners = ref([])
const middle = ref(null)
const notice = ref(null)
const scenes = ref([])
const goods = ref([])
const guides = ref([])
const helps = ref([])
const loading = ref(true)
const slide = ref(0)

const pad = (n) => String(n).padStart(2, '0')

// 轮播图、横幅点击：按后台配置的跳转类型处理
function go(b) {
  if (!b) return
  if (b.link_type === 'goods') router.push({ name: 'goods-detail', params: { id: b.link_value } })
  else if (b.link_type === 'category') router.push({ name: 'goods', query: { category_id: b.link_value } })
  else if (b.link_type === 'article') router.push({ name: 'article-detail', params: { id: b.link_value } })
  else if (b.link_type === 'url' && /^https?:\/\//i.test(b.link_value)) window.open(b.link_value, '_blank', 'noopener')
  else router.push({ name: 'goods' })
}

onMounted(() => {
  getAds('home_banner,home_middle').then((d) => {
    banners.value = d.home_banner || []
    middle.value = (d.home_middle || [])[0] || null
  }).catch(() => {})
  getArticles({ type: 'notice', size: 1 }).then((d) => { notice.value = d.list[0] || null }).catch(() => {})
  // 场景入口用一级分类，最多 6 个；配了图片的排在前面
  getCategories().then((d) => { scenes.value = [...d.filter((c) => c.image), ...d.filter((c) => !c.image)].slice(0, 6) }).catch(() => {})
  getArticles({ type: 'article', size: 3 }).then((d) => { guides.value = d.list }).catch(() => {})
  // 帮助入口：每个帮助分类一个，点进去看这个分类的第一篇
  getHelp().then((d) => { helps.value = d.filter((c) => c.articles.length).slice(0, 3) }).catch(() => {})
  getGoodsList({ page: 1, size: 8 }).then((d) => { goods.value = d.list }).catch(() => {}).finally(() => { loading.value = false })
})
</script>

<template>
  <!-- 主视觉：和下面的内容区等宽（不铺满窗口，宽屏上才能和其他区块对齐），文字压在轮播图左侧 -->
  <section class="container hero">
    <el-carousel v-if="banners.length" height="440px" :interval="6000" arrow="never" indicator-position="none" @change="slide = $event">
      <el-carousel-item v-for="b in banners" :key="b.id">
        <div class="slide">
          <img :src="b.image" alt="" class="slide-img" />
          <div class="slide-shade" />
          <div class="slide-body">
            <div class="eyebrow">{{ t('pc.heroEyebrow') }}</div>
            <h1 class="hero-title">{{ b.title }}</h1>
            <p class="hero-sub">{{ t('pc.heroSub') }}</p>
            <div class="hero-actions">
              <button class="btn solid" @click="go(b)">{{ t('pc.heroCta') }}</button>
              <router-link :to="{ name: 'articles', query: { type: 'article' } }" class="btn ghost">{{ t('pc.heroGuide') }}</router-link>
            </div>
          </div>
        </div>
      </el-carousel-item>
    </el-carousel>
    <div v-else class="slide empty-hero">
      <div class="slide-body">
        <div class="eyebrow">{{ t('pc.heroEyebrow') }}</div>
        <h1 class="hero-title">{{ t('pc.heroFallback') }}</h1>
        <p class="hero-sub">{{ t('pc.heroSub') }}</p>
        <div class="hero-actions"><router-link :to="{ name: 'goods' }" class="btn solid">{{ t('pc.heroCta') }}</router-link></div>
      </div>
    </div>
    <div v-if="banners.length > 1" class="hero-count">{{ pad(slide + 1) }} / {{ pad(banners.length) }}</div>
  </section>

  <div class="container">
    <router-link v-if="notice" :to="{ name: 'article-detail', params: { id: notice.id } }" class="notice">
      <el-icon class="notice-icon"><Bell /></el-icon>
      <span class="notice-tag">{{ t('home.notice') }}</span>
      <span class="ellipsis notice-text">{{ notice.title }}</span>
      <el-icon class="notice-arrow"><ArrowRight /></el-icon>
    </router-link>

    <section v-if="scenes.length" class="block">
      <h2 class="heading">{{ t('pc.sceneTitle') }}</h2>
      <div class="scenes">
        <router-link v-for="c in scenes" :key="c.id" :to="{ name: 'goods', query: { category_id: c.id } }" class="scene">
          <div class="scene-img"><img v-if="c.image" :src="c.image" alt="" loading="lazy" /></div>
          <div class="scene-name ellipsis">{{ c.name }}</div>
        </router-link>
      </div>
    </section>

    <section class="block">
      <div class="heading-row">
        <div>
          <h2 class="heading">{{ t('home.recommend') }}</h2>
          <div class="heading-sub">{{ t('pc.recommendSub') }}</div>
        </div>
        <router-link :to="{ name: 'goods' }" class="more">{{ t('pc.viewAll') }}<el-icon><ArrowRight /></el-icon></router-link>
      </div>
      <div v-loading="loading" class="grid">
        <GoodsCard v-for="g in goods" :key="g.id" :g="g" />
      </div>
      <div v-if="!loading && !goods.length" class="empty">{{ t('common.empty') }}</div>
    </section>

    <!-- 主题横幅：后台“首页中部”广告位的第一张 -->
    <section v-if="middle" class="band" @click="go(middle)">
      <img :src="middle.image" alt="" class="band-img" />
      <div class="band-body">
        <h2 class="band-title">{{ middle.title }}</h2>
        <p class="band-sub">{{ t('pc.bandSub') }}</p>
        <span class="btn solid">{{ t('pc.bandCta') }}</span>
      </div>
    </section>

    <section v-if="guides.length" class="block">
      <div class="heading-row">
        <h2 class="heading">{{ t('pc.guideTitle') }}</h2>
        <router-link :to="{ name: 'articles', query: { type: 'article' } }" class="more">{{ t('pc.viewAll') }}<el-icon><ArrowRight /></el-icon></router-link>
      </div>
      <div class="guides">
        <router-link v-for="a in guides" :key="a.id" :to="{ name: 'article-detail', params: { id: a.id } }" class="guide">
          <div class="guide-img"><img v-if="a.cover" :src="a.cover" alt="" loading="lazy" /></div>
          <div class="guide-body">
            <div class="guide-title ellipsis-2">{{ a.title }}</div>
            <div class="guide-sum ellipsis-2">{{ a.summary }}</div>
            <span class="guide-more">{{ t('pc.readGuide') }}<el-icon><ArrowRight /></el-icon></span>
          </div>
        </router-link>
      </div>
    </section>

    <section v-if="helps.length" class="help">
      <router-link :to="{ name: 'articles', query: { type: 'help' } }" class="help-lead">
        <el-icon class="help-q"><QuestionFilled /></el-icon>{{ t('pc.needHelp') }}
      </router-link>
      <router-link v-for="h in helps" :key="h.id" :to="{ name: 'article-detail', params: { id: h.articles[0].id } }" class="help-item">
        <span class="ellipsis">{{ h.name }}</span><el-icon><ArrowRight /></el-icon>
      </router-link>
    </section>
  </div>
</template>

<style scoped>
/* ---- 主视觉 */
.hero { position: relative; border-radius: var(--radius); overflow: hidden; }
.slide { position: relative; height: 440px; background: var(--cream-deep); overflow: hidden; }
/* 图片铺满并裁切，靠右对齐：主体在右侧，窗口窄的时候裁掉的是左边被文字盖住的那一段 */
.slide-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: right center; }
/* 左侧压一层奶油色渐变，保证文字在任何图片上都看得清；用比页面底色深一档的颜色，主视觉的左边缘才看得出来 */
.slide-shade { position: absolute; inset: 0; background: linear-gradient(90deg, rgba(240, 233, 221, 0.96) 0%, rgba(240, 233, 221, 0.8) 34%, rgba(240, 233, 221, 0) 62%); }
.empty-hero { background: linear-gradient(120deg, var(--cream-deep), #ead9c8); }
.slide-body { position: relative; height: 100%; padding: 0 56px; display: flex; flex-direction: column; justify-content: center; }
.eyebrow { font-size: 13px; letter-spacing: 0.32em; color: var(--text); opacity: 0.75; }
.hero-title { margin: 14px 0 12px; max-width: 520px; font-size: 46px; line-height: 1.22; font-weight: 700; letter-spacing: 0.02em; color: var(--text); }
.hero-sub { margin: 0; font-size: 17px; color: var(--text); opacity: 0.8; }
.hero-actions { display: flex; gap: 14px; margin-top: 30px; }
.hero-count { position: absolute; right: 28px; bottom: 20px; font-size: 13px; letter-spacing: 0.08em; color: var(--text); opacity: 0.7; z-index: 3; pointer-events: none; }

.btn { display: inline-flex; align-items: center; justify-content: center; height: 44px; padding: 0 30px; border-radius: 22px; font-size: 15px; cursor: pointer; border: 1.5px solid var(--theme); font-family: inherit; transition: background 0.15s, color 0.15s; }
.btn.solid { background: var(--theme); color: #fff; }
.btn.solid:hover { background: var(--theme-dark); border-color: var(--theme-dark); }
.btn.ghost { background: rgba(255, 255, 255, 0.6); color: var(--theme); }
.btn.ghost:hover { background: #fff; }

/* ---- 公告 */
.notice { display: flex; align-items: center; gap: 10px; margin-top: 18px; padding: 0 18px; height: 44px; background: var(--cream-deep); border-radius: var(--radius); }
.notice-icon, .notice-tag { color: var(--theme); }
.notice-tag { font-weight: 600; flex: none; }
.notice-text { flex: 1; color: var(--text); }
.notice-arrow { color: var(--sub); }
.notice:hover .notice-text { color: var(--theme); }

/* ---- 各区块的标题 */
.block { margin-top: 40px; }
.heading { margin: 0; font-size: 24px; font-weight: 700; letter-spacing: 0.02em; }
.heading-sub { margin-top: 6px; color: var(--sub); }
.heading-row { display: flex; justify-content: space-between; align-items: flex-end; }
.more { display: inline-flex; align-items: center; gap: 6px; color: var(--theme); font-size: 14px; }
.more:hover { color: var(--theme-dark); }

/* ---- 场景入口 */
.scenes { display: grid; grid-template-columns: repeat(6, 1fr); gap: 16px; margin-top: 18px; }
.scene { background: #fff; border-radius: var(--radius); overflow: hidden; transition: transform 0.18s, box-shadow 0.18s; }
.scene:hover { transform: translateY(-3px); box-shadow: 0 10px 24px rgba(41, 43, 39, 0.08); }
.scene-img { height: 140px; background: var(--cream-deep); }
.scene-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.scene-name { padding: 12px 10px; text-align: center; font-size: 15px; }
.scene:hover .scene-name { color: var(--theme); }

/* ---- 推荐商品 */
.grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-top: 18px; min-height: 120px; }

/* ---- 主题横幅 */
.band { position: relative; margin-top: 40px; height: 220px; border-radius: var(--radius); overflow: hidden; background: var(--sage); cursor: pointer; }
.band-img { position: absolute; right: 0; top: 0; width: 62%; height: 100%; object-fit: cover; object-position: right center; -webkit-mask-image: linear-gradient(90deg, transparent 0, #000 38%); mask-image: linear-gradient(90deg, transparent 0, #000 38%); }
.band-body { position: relative; height: 100%; padding: 0 56px; display: flex; flex-direction: column; justify-content: center; align-items: flex-start; }
.band-title { margin: 0; font-size: 32px; font-weight: 700; letter-spacing: 0.06em; color: #fff; max-width: 560px; }
.band-sub { margin: 10px 0 20px; font-size: 16px; color: rgba(255, 255, 255, 0.9); letter-spacing: 0.08em; }
.band .btn { height: 40px; }

/* ---- 选购指南 */
.guides { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 18px; }
.guide { display: flex; background: #fff; border-radius: var(--radius); overflow: hidden; transition: box-shadow 0.18s; }
.guide:hover { box-shadow: 0 10px 24px rgba(41, 43, 39, 0.08); }
.guide-img { width: 168px; height: 160px; flex: none; background: var(--cream-deep); }
.guide-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.guide-body { flex: 1; min-width: 0; padding: 20px 18px; display: flex; flex-direction: column; }
.guide-title { font-size: 16px; font-weight: 600; line-height: 1.45; }
.guide-sum { margin-top: 8px; color: var(--sub); font-size: 13px; line-height: 1.6; }
.guide-more { margin-top: auto; display: inline-flex; align-items: center; gap: 6px; color: var(--theme); font-size: 13px; }
.guide:hover .guide-title { color: var(--theme); }

/* ---- 帮助入口 */
.help { display: flex; align-items: center; margin-top: 28px; height: 56px; background: var(--cream-deep); border-radius: var(--radius); }
.help-lead { display: flex; align-items: center; gap: 12px; padding: 0 36px; font-weight: 600; flex: none; }
.help-q { font-size: 26px; color: var(--theme); }
.help-item { flex: 1; min-width: 0; display: flex; align-items: center; justify-content: center; gap: 12px; height: 24px; border-left: 1px solid var(--line); padding: 0 20px; }
.help-item:hover, .help-lead:hover { color: var(--theme); }
</style>
