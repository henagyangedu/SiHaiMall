<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { getGoods, addCart } from '@/api'
import { needLogin } from '@/utils/request'
import { refreshCartCount } from '@/utils/app'
import { safeHtml } from '@/utils/html'
import GoodsReviews from '@/components/GoodsReviews.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const goods = ref(null)
const failed = ref(false)
const picked = reactive({}) // 规格项 ID => 选中的规格值 ID
const qty = ref(1)
const current = ref('') // 当前显示的大图

// 选齐了规格才能确定是哪个 SKU；单规格直接取唯一的那个
const sku = computed(() => {
  const g = goods.value
  if (!g) return null
  if (!g.specs.length) return g.skus[0] || null
  const ids = g.specs.map((s) => picked[s.id])
  if (ids.some((v) => !v)) return null
  return g.skus.find((k) => k.spec_value_ids.length === ids.length && ids.every((v) => k.spec_value_ids.includes(v))) || null
})
const images = computed(() => {
  const g = goods.value
  if (!g) return []
  const list = g.images.length ? g.images : (g.cover ? [g.cover] : [])
  return sku.value?.image && !list.includes(sku.value.image) ? [sku.value.image, ...list] : list
})
// 没选规格时显示最低价
const price = computed(() => {
  if (sku.value) return sku.value.price
  const all = (goods.value?.skus || []).map((k) => Number(k.price))
  return all.length ? Math.min(...all).toFixed(2) : '0.00'
})
const maxQty = computed(() => Math.max(1, Math.min(sku.value ? sku.value.stock : 999, 999)))
watch(maxQty, (m) => { if (qty.value > m) qty.value = m })
// 选中带图的规格时，大图切到这个规格的图
watch(sku, (k) => { if (k?.image) current.value = k.image })

function pick(specId, valueId) {
  picked[specId] = picked[specId] === valueId ? 0 : valueId
}
// 加购和立即购买共用的检查：规格选齐、库存够
function ready() {
  if (!sku.value) { ElMessage.warning(t('goods.chooseSpec')); return false }
  if (sku.value.stock < qty.value) { ElMessage.warning(t('goods.noStock')); return false }
  return true
}
function onAddCart() {
  if (!ready()) return
  needLogin(async () => {
    await addCart(sku.value.id, qty.value)
    ElMessage.success(t('goods.added'))
    refreshCartCount()
  })
}
function onBuy() {
  if (!ready()) return
  needLogin(() => router.push({ name: 'checkout', query: { sku_id: sku.value.id, qty: qty.value } }))
}

onMounted(async () => {
  try {
    goods.value = await getGoods(route.params.id)
    current.value = images.value[0] || ''
  } catch (e) {
    failed.value = true
  }
})
</script>

<template>
  <div v-if="failed" class="panel empty">
    <div>{{ t('goods.offShelf') }}</div>
    <el-button style="margin-top: 20px" @click="router.push({ name: 'home' })">{{ t('pc.backHome') }}</el-button>
  </div>

  <template v-else-if="goods">
    <div class="panel top">
      <div class="gallery">
        <div class="big"><img v-if="current" :src="current" :alt="goods.title" /></div>
        <div v-if="images.length > 1" class="thumbs">
          <img v-for="(u, i) in images" :key="i" :src="u" alt="" :class="{ on: u === current }" @mouseenter="current = u" />
        </div>
      </div>

      <div class="info">
        <h1 class="title">{{ goods.title }}</h1>
        <div v-if="goods.subtitle" class="sub subtitle">{{ goods.subtitle }}</div>
        <div class="price-box">
          <span class="price big-price">{{ goods.symbol }}{{ price }}</span>
          <span class="sub">{{ t('home.sold', { n: goods.sales }) }}<template v-if="sku">　{{ t('goods.stock', { n: sku.stock }) }}</template></span>
        </div>

        <div v-for="s in goods.specs" :key="s.id" class="row">
          <div class="label">{{ s.name }}</div>
          <div class="values">
            <span v-for="v in s.values" :key="v.id" class="value" :class="{ on: picked[s.id] === v.id }" @click="pick(s.id, v.id)">{{ v.name }}</span>
          </div>
        </div>
        <div class="row">
          <div class="label">{{ t('goods.qty') }}</div>
          <el-input-number v-model="qty" :min="1" :max="maxQty" />
        </div>

        <div class="actions">
          <el-button size="large" type="primary" plain @click="onAddCart">{{ t('goods.addCart') }}</el-button>
          <el-button size="large" type="primary" @click="onBuy">{{ t('goods.buyNow') }}</el-button>
        </div>
      </div>
    </div>

    <div v-if="goods.description" class="panel">
      <div class="panel-title">{{ t('goods.description') }}</div>
      <!-- 详情是后台富文本编辑器保存的内容，后端不过滤，渲染前必须先清洗（见 utils/html.js） -->
      <div class="desc" v-html="safeHtml(goods.description)" />
    </div>

    <GoodsReviews :goods-id="goods.id" />
  </template>
</template>

<style scoped>
.top { display: flex; gap: 32px; }
.gallery { width: 420px; flex: none; }
.big { width: 420px; height: 420px; background: #f0f1f3; border-radius: 8px; overflow: hidden; }
.big img { width: 100%; height: 100%; object-fit: cover; display: block; }
.thumbs { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
.thumbs img { width: 64px; height: 64px; object-fit: cover; border-radius: 6px; border: 2px solid transparent; cursor: pointer; }
.thumbs img.on { border-color: var(--theme); }
.info { flex: 1; min-width: 0; }
.title { font-size: 22px; margin: 0 0 8px; line-height: 1.4; }
.subtitle { margin-bottom: 12px; }
.price-box { background: #fcf5f5; border-radius: 8px; padding: 16px 20px; display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 20px; }
.big-price { font-size: 30px; }
.row { display: flex; align-items: flex-start; margin-bottom: 18px; }
.label { width: 80px; flex: none; color: var(--sub); line-height: 34px; }
.values { display: flex; flex-wrap: wrap; gap: 10px; }
.value { padding: 6px 16px; border: 1px solid #dcdfe6; border-radius: 6px; cursor: pointer; }
.value:hover { border-color: var(--theme); }
.value.on { border-color: var(--theme); color: var(--theme); background: #fcebeb; }
.actions { margin-top: 28px; }
.desc { line-height: 1.8; overflow: hidden; }
.desc :deep(img) { max-width: 100%; height: auto; }
</style>
