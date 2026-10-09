<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { getGoodsReviews } from '@/api'

// 商品详情页的评价区：概况、筛选、列表、翻页
const props = defineProps({ goodsId: { type: Number, required: true } })
const { t } = useI18n()

const summary = ref(null)
const list = ref([])
const level = ref('all')
const page = reactive({ no: 1, size: 10, total: 0, loading: false })

// 筛选：全部 / 好评 / 中评 / 差评 / 有图，括号里是各自的条数
const LEVELS = [['all', 'count'], ['good', 'good'], ['mid', 'mid'], ['bad', 'bad'], ['image', 'with_images']]

async function load() {
  page.loading = true
  try {
    const d = await getGoodsReviews(props.goodsId, { level: level.value, page: page.no, size: page.size })
    summary.value = d.summary
    list.value = d.list
    page.total = d.total
  } finally {
    page.loading = false
  }
}
function pick(l) {
  if (level.value === l) return
  level.value = l
  page.no = 1
  load()
}

onMounted(load)
</script>

<template>
  <div v-if="summary" class="panel reviews">
    <div class="panel-title">{{ t('review.title') }}<span class="sub count">（{{ summary.count }}）</span></div>
    <div class="head">
      <div class="rate"><span class="num">{{ summary.good_rate }}%</span>{{ t('review.goodRate') }}</div>
      <div class="levels">
        <span v-for="[l, key] in LEVELS" :key="l" class="level" :class="{ on: level === l }" @click="pick(l)">{{ t(`review.level.${l}`) }} {{ summary[key] }}</span>
      </div>
    </div>

    <div v-loading="page.loading">
      <div v-for="r in list" :key="r.id" class="rv">
        <div class="who">
          <img v-if="r.avatar" :src="r.avatar" alt="" class="avatar" />
          <div v-else class="avatar ph">{{ (r.nickname || '?').slice(0, 1) }}</div>
          <div class="name ellipsis">{{ r.nickname }}</div>
        </div>
        <div class="main">
          <el-rate :model-value="r.rating" disabled />
          <div v-if="r.content" class="content">{{ r.content }}</div>
          <div v-if="r.images.length" class="pics">
            <el-image v-for="(u, i) in r.images" :key="i" :src="u" :preview-src-list="r.images" :initial-index="i" fit="cover" preview-teleported class="pic" />
          </div>
          <div class="sub meta">{{ r.created_at }}<template v-if="r.spec_text">　{{ r.spec_text }}</template></div>
          <div v-if="r.reply" class="reply"><span class="reply-by">{{ t('review.reply') }}</span>{{ r.reply }}</div>
        </div>
      </div>
      <div v-if="!list.length && !page.loading" class="empty">{{ t('review.empty') }}</div>
    </div>

    <div v-if="page.total > page.size" class="pager">
      <el-pagination v-model:current-page="page.no" :page-size="page.size" :total="page.total" layout="prev, pager, next" background @current-change="load" />
    </div>
  </div>
</template>

<style scoped>
.count { font-weight: 400; }
.head { display: flex; align-items: center; gap: 32px; padding-bottom: 16px; border-bottom: 1px solid var(--line); }
.rate { color: var(--sub); white-space: nowrap; }
.num { color: var(--theme); font-size: 28px; font-weight: 600; margin-right: 8px; }
.levels { display: flex; flex-wrap: wrap; gap: 10px; }
.level { padding: 4px 14px; border-radius: 14px; background: #f5f6f8; cursor: pointer; font-size: 13px; }
.level.on { background: #fcf5f5; color: var(--theme); }
.rv { display: flex; gap: 24px; padding: 20px 0; border-bottom: 1px solid var(--line); }
.who { width: 120px; flex: none; text-align: center; }
.avatar { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; background: #f0f1f3; display: inline-block; }
.ph { line-height: 44px; color: var(--sub); }
.name { margin-top: 6px; font-size: 13px; }
.main { flex: 1; min-width: 0; }
.content { margin-top: 8px; line-height: 1.7; word-break: break-all; white-space: pre-wrap; }
.pics { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.pic { width: 80px; height: 80px; border-radius: 6px; }
.meta { margin-top: 8px; }
.reply { margin-top: 10px; padding: 10px 14px; background: #f5f6f8; border-radius: 6px; line-height: 1.6; }
.reply-by { color: var(--theme); margin-right: 8px; }
.empty { text-align: center; color: var(--sub); padding: 40px 0; }
.pager { display: flex; justify-content: center; padding-top: 16px; }
</style>
