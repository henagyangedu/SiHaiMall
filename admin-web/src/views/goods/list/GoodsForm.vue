<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getGoods, createGoods, updateGoods } from '@/api/goods'
import { categoryTree } from '@/api/category'
import { brandOptions } from '@/api/brand'
import { shippingTemplateOptions } from '@/api/shipping'
import { saleRegionOptions } from '@/api/saleRegion'
import { currencyOptions } from '@/api/option'
import { languageOptions } from '@/api/language'
import { translateText } from '@/api/translate'
import { hasPerm } from '@/utils/perm'
import LangInput from '@/components/LangInput.vue'
import LangRichText from '@/components/LangRichText.vue'
import ImageListUpload from '@/components/ImageListUpload.vue'
import StockDialog from './StockDialog.vue'
import SpecEditor from './SpecEditor.vue'
import SkuTable from './SkuTable.vue'

// id 为空表示新增；保存成功触发 saved；initTab 指定打开时停留的页签
const props = defineProps({
  modelValue: Boolean,
  id: { type: Number, default: 0 },
  initTab: { type: String, default: 'base' },
})
const emit = defineEmits(['update:modelValue', 'saved'])

const isEdit = computed(() => !!props.id)
const loading = ref(false)
const saving = ref(false)
const tab = ref('base')

const languages = ref([])
const categories = ref([])
const brands = ref([])
const shippingTemplates = ref([])
const saleRegions = ref([])
const currencies = ref([])
const baseCurrency = computed(() => currencies.value.find((c) => c.is_base === 1))
const otherCurrencies = computed(() => currencies.value.filter((c) => c.is_base !== 1))
const defaultLang = computed(() => languages.value.find((l) => l.is_default)?.code || '')

const newSku = (keys) => ({
  id: 0, keys, sku_sn: '', image: '', price: 0, market_price: 0, cost_price: 0,
  stock: 0, warn_stock: 0, weight: 0, volume: 0, status: 1, prices: {}, region_prices: {},
})
const emptyForm = () => ({
  id: 0, category_id: null, brand_id: 0, shipping_template_id: 0, goods_sn: '',
  title: {}, subtitle: {}, keywords: {}, description: {}, seo_title: {}, seo_desc: {},
  images: [], video: '', unit: '', is_virtual: 0, is_on_sale: 0, sort: 0, spec_type: 1,
  sku: { sku_sn: '', price: 0, market_price: 0, cost_price: 0, stock: 0, warn_stock: 0, weight: 0, volume: 0 },
  prices: {},
  region_prices: {},
  specs: [],
  skus: [],
})
const form = reactive(emptyForm())

function simplify(nodes) {
  return nodes.map((n) => ({ id: n.id, name_text: n.name_text, disabled: n.status !== 1, children: simplify(n.children || []) }))
}
const categoryOptions = computed(() => simplify(categories.value))
const numPrices = (p) => Object.fromEntries(Object.entries(p || {}).map(([k, v]) => [k, Number(v)]))

async function loadAll() {
  loading.value = true
  tab.value = props.initTab || 'base'
  try {
    const [langs, cats, bs, curs, tpls, regions] = await Promise.all([
      languageOptions(), categoryTree(), brandOptions(), currencyOptions(), shippingTemplateOptions(), saleRegionOptions(),
    ])
    languages.value = langs
    categories.value = cats
    brands.value = bs
    shippingTemplates.value = tpls
    saleRegions.value = regions
    currencies.value = curs

    Object.assign(form, emptyForm())
    if (props.id) {
      const d = await getGoods(props.id)
      Object.assign(form, d, {
        sku: { ...emptyForm().sku, ...(d.sku || {}) },
        prices: numPrices(d.prices),
        region_prices: numPrices(d.region_prices),
        specs: (d.specs || []).map((s) => ({
          id: s.id,
          name: { ...s.name },
          values: s.values.map((v) => ({ id: v.id, key: `v${v.id}`, name: { ...v.name } })),
        })),
        skus: (d.skus || []).map((r) => ({
          id: r.id, keys: r.value_ids.map((i) => `v${i}`), sku_sn: r.sku_sn, image: r.image,
          price: Number(r.price), market_price: Number(r.market_price), cost_price: Number(r.cost_price),
          stock: r.stock, warn_stock: r.warn_stock, weight: Number(r.weight), volume: Number(r.volume),
          status: r.status, prices: numPrices(r.prices), region_prices: numPrices(r.region_prices),
        })),
      })
      for (const k of ['price', 'market_price', 'cost_price', 'weight', 'volume']) form.sku[k] = Number(form.sku[k] || 0)
    }
  } finally {
    loading.value = false
  }
}
watch(() => props.modelValue, (v) => { if (v) loadAll() })

// ---- 多规格：规格值变化时重建 SKU 表，已有组合的数据按组合保留 ----
function combos(specs) {
  const lists = specs.map((s) => s.values.map((v) => v.key))
  if (!lists.length || lists.some((l) => !l.length)) return []
  return lists.reduce((acc, l) => acc.flatMap((a) => l.map((k) => [...a, k])), [[]])
}
function rebuildSkus() {
  const old = new Map(form.skus.map((r) => [r.keys.join('|'), r]))
  form.skus = combos(form.specs).map((keys) => old.get(keys.join('|')) || newSku(keys))
}
const specSignature = computed(() => (form.spec_type === 2 ? form.specs.map((s) => s.values.map((v) => v.key).join(',')).join('|') : ''))
watch(specSignature, rebuildSkus)

function onTypeChange(v) {
  if (v === 2 && !form.specs.length) {
    form.specs.push({ id: 0, name: {}, values: [{ id: 0, key: `n0${Math.random().toString(36).slice(2, 6)}`, name: {} }] })
  }
}

async function submit() {
  saving.value = true
  try {
    const payload = {
      category_id: form.category_id, brand_id: form.brand_id || 0, shipping_template_id: form.shipping_template_id || 0, goods_sn: form.goods_sn,
      title: form.title, subtitle: form.subtitle, keywords: form.keywords, description: form.description,
      seo_title: form.seo_title, seo_desc: form.seo_desc,
      images: form.images, video: form.video, unit: form.unit,
      is_virtual: form.is_virtual, is_on_sale: form.is_on_sale, sort: form.sort, spec_type: form.spec_type,
    }
    if (form.spec_type === 1) {
      payload.sku = { ...form.sku }
      payload.prices = { ...form.prices }
      payload.region_prices = { ...form.region_prices }
      if (isEdit.value) delete payload.sku.stock // 库存只能通过“调整库存”修改
    } else {
      payload.specs = form.specs.map((s) => ({
        id: s.id || null,
        name: s.name,
        values: s.values.map((v) => ({ id: v.id || null, key: v.key, name: v.name })),
      }))
      payload.skus = form.skus.map((r) => {
        const o = {
          id: r.id || null, value_keys: r.keys, sku_sn: r.sku_sn, image: r.image,
          price: r.price, market_price: r.market_price, cost_price: r.cost_price,
          warn_stock: r.warn_stock, weight: r.weight, volume: r.volume, status: r.status, prices: { ...r.prices }, region_prices: { ...r.region_prices },
        }
        if (!r.id) o.stock = r.stock // 已有规格组合的库存只能“调整”
        return o
      })
    }

    if (isEdit.value) await updateGoods(props.id, payload)
    else await createGoods(payload)
    ElMessage.success('保存成功')
    emit('update:modelValue', false)
    emit('saved')
  } finally {
    saving.value = false
  }
}

// ---- AI 一键翻译：默认语言 → 其它启用的语言 ----
const aiLoading = ref(false)
const AI_FIELDS = ['title', 'subtitle', 'keywords', 'seo_title', 'seo_desc', 'description']
async function aiTranslateAll() {
  const from = defaultLang.value
  const src = {}
  AI_FIELDS.forEach((f) => {
    const t = (form[f]?.[from] || '').trim()
    if (t) src[f] = t
  })
  if (!Object.keys(src).length) {
    ElMessage.warning('请先填写默认语言的标题等内容')
    return
  }
  const targets = languages.value.filter((l) => !l.is_default)
  if (!targets.length) {
    ElMessage.warning('没有其它启用的语言')
    return
  }

  let onlyEmpty = true
  try {
    await ElMessageBox.confirm(
      `将把默认语言的内容翻译成：${targets.map((l) => l.name).join('、')}。\n目标语言里已有内容时怎么处理？`,
      'AI 一键翻译',
      { confirmButtonText: '仅填写空白', cancelButtonText: '覆盖已有内容', distinguishCancelAndClose: true, type: 'info' },
    )
  } catch (action) {
    if (action === 'cancel') onlyEmpty = false
    else return
  }

  aiLoading.value = true
  const warnings = []
  const failed = []
  try {
    for (const l of targets) {
      const fields = {}
      for (const f of Object.keys(src)) {
        const exist = (form[f]?.[l.code] || '').trim()
        if (!onlyEmpty || !exist) fields[f] = src[f]
      }
      if (!Object.keys(fields).length) continue
      try {
        const res = await translateText({ from, to: l.code, fields })
        for (const [f, v] of Object.entries(res.fields)) form[f] = { ...(form[f] || {}), [l.code]: v }
        ;(res.warnings || []).forEach((w) => warnings.push(`${l.name}：${w}`))
      } catch (e) {
        failed.push(l.name) // 具体原因已由请求层弹出
      }
    }
  } finally {
    aiLoading.value = false
  }
  if (failed.length) ElMessage.error(`${failed.join('、')} 翻译失败，可稍后重试`)
  else ElMessage.success('翻译完成，请检查后保存')
  if (warnings.length) ElMessageBox.alert(warnings.join('\n'), '译文提示（请重点检查）', { type: 'warning' })
}

// ---- 编辑时调整库存（单规格整体调整；多规格按 SKU 调整） ----
const stockDialog = ref(false)
const stockGoods = ref(null)
const stockSku = ref(null)
const goodsTitle = () => form.title[defaultLang.value] || ''
function openSingleStock() {
  stockGoods.value = { id: props.id, title: goodsTitle(), stock: form.sku.stock }
  stockSku.value = null
  stockDialog.value = true
}
function openSkuStock(row, label) {
  stockGoods.value = { id: props.id, title: goodsTitle(), stock: 0 }
  stockSku.value = { id: row.id, label, stock: row.stock }
  stockDialog.value = true
}
async function onStockDone() {
  const d = await getGoods(props.id)
  if (form.spec_type === 1) {
    form.sku.stock = d.sku?.stock ?? 0
  } else {
    form.skus.forEach((r) => {
      const s = (d.skus || []).find((x) => x.id === r.id)
      if (s) r.stock = s.stock
    })
  }
}
</script>

<template>
  <el-drawer :model-value="modelValue" :title="isEdit ? '编辑商品' : '新增商品'" size="1000px" destroy-on-close @update:model-value="emit('update:modelValue', $event)">
    <el-form v-loading="loading" :model="form" label-width="110px">
      <div v-if="languages.length > 1" class="ai-bar">
        <el-button type="warning" plain :loading="aiLoading" @click="aiTranslateAll">AI 一键翻译全部语言</el-button>
        <span class="hint-inline">把默认语言的标题、副标题、关键词、SEO、详情翻译成其它语言；译文需检查后再保存</span>
      </div>
      <el-tabs v-model="tab">
        <el-tab-pane label="基本信息" name="base">
          <el-form-item label="商品标题" required><LangInput v-model="form.title" :languages="languages" :maxlength="255" ai field-key="title" /></el-form-item>
          <el-form-item label="副标题"><LangInput v-model="form.subtitle" :languages="languages" :maxlength="255" ai field-key="subtitle" /></el-form-item>
          <el-form-item label="商品分类" required>
            <el-tree-select v-model="form.category_id" :data="categoryOptions" node-key="id" check-strictly default-expand-all placeholder="请选择分类" :props="{ label: 'name_text', children: 'children', disabled: 'disabled' }" style="width: 320px" />
          </el-form-item>
          <el-form-item label="品牌">
            <el-select v-model="form.brand_id" clearable placeholder="可不选" style="width: 320px">
              <el-option v-for="b in brands" :key="b.id" :label="b.name" :value="b.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="商品图片"><ImageListUpload v-model="form.images" :max="9" scene="goods" /></el-form-item>
          <el-form-item label="视频地址"><el-input v-model="form.video" placeholder="可选，视频文件地址" /></el-form-item>
          <el-form-item label="商品编码"><el-input v-model="form.goods_sn" maxlength="60" placeholder="留空自动生成" style="width: 320px" /></el-form-item>
          <el-form-item label="计量单位"><el-input v-model="form.unit" maxlength="20" placeholder="如 件、套、kg" style="width: 200px" /></el-form-item>
          <el-form-item label="虚拟商品"><el-switch v-model="form.is_virtual" :active-value="1" :inactive-value="0" /><span class="hint-inline">虚拟商品无需发货</span></el-form-item>
          <el-form-item v-if="!form.is_virtual" label="运费模板">
            <el-select v-model="form.shipping_template_id" style="width: 320px">
              <el-option label="包邮（不使用运费模板）" :value="0" />
              <el-option v-for="t in shippingTemplates" :key="t.id" :label="t.name" :value="t.id" />
            </el-select>
            <span class="hint-inline">在 设置 → 发货设置 → 运费模板 里维护</span>
          </el-form-item>
          <el-form-item label="排序"><el-input-number v-model="form.sort" :min="0" :max="9999" /><span class="hint-inline">越小越靠前</span></el-form-item>
          <el-form-item label="立即上架"><el-switch v-model="form.is_on_sale" :active-value="1" :inactive-value="0" /><span class="hint-inline">上架商品必须有图片和至少一个启用的规格</span></el-form-item>
        </el-tab-pane>

        <el-tab-pane label="价格库存" name="price">
          <el-form-item label="规格类型">
            <el-radio-group v-model="form.spec_type" :disabled="isEdit" @change="onTypeChange">
              <el-radio :value="1">单规格</el-radio>
              <el-radio :value="2">多规格（颜色、尺码等）</el-radio>
            </el-radio-group>
            <span v-if="isEdit" class="hint-inline">规格类型创建后不能更改</span>
          </el-form-item>

          <!-- 单规格 -->
          <template v-if="form.spec_type === 1">
            <el-form-item :label="`售价（${baseCurrency?.code || '基准币种'}）`" required>
              <el-input-number v-model="form.sku.price" :min="0" :precision="2" :controls="false" style="width: 200px" />
            </el-form-item>
            <el-form-item label="划线价"><el-input-number v-model="form.sku.market_price" :min="0" :precision="2" :controls="false" style="width: 200px" /></el-form-item>
            <el-form-item label="成本价"><el-input-number v-model="form.sku.cost_price" :min="0" :precision="2" :controls="false" style="width: 200px" /></el-form-item>

            <el-form-item v-if="otherCurrencies.length" label="其它币种定价">
              <div class="price-grid">
                <div v-for="c in otherCurrencies" :key="c.code" class="price-row">
                  <span class="cur">{{ c.code }}</span>
                  <el-input-number v-model="form.prices[c.code]" :min="0" :precision="2" :controls="false" placeholder="留空按汇率折算" style="width: 200px" />
                  <span class="hint-inline">汇率 1 {{ baseCurrency?.code }} = {{ Number(c.rate) }} {{ c.code }}；留空则自动折算</span>
                </div>
              </div>
            </el-form-item>

            <el-form-item v-if="saleRegions.length" label="销售区域价">
              <div class="price-grid">
                <div v-for="r in saleRegions" :key="r.id" class="price-row">
                  <span class="region">{{ r.name }}</span>
                  <el-input-number v-model="form.region_prices[r.id]" :min="0" :precision="2" :controls="false" placeholder="留空按标准价" style="width: 200px" />
                  <span class="hint-inline">{{ r.currency }}</span>
                </div>
                <div class="hint-inline" style="margin-left: 0">收货地址属于该区域时按区域价销售，留空则按上面的标准价。区域在 商品 → 销售区域 里维护</div>
              </div>
            </el-form-item>

            <el-form-item label="库存">
              <el-input-number v-if="!isEdit" v-model="form.sku.stock" :min="0" :max="99999999" />
              <template v-else>
                <b style="margin-right: 12px">{{ form.sku.stock }}</b>
                <el-button v-perm="'goods:list:stock'" size="small" @click="openSingleStock">调整库存</el-button>
                <span class="hint-inline">库存修改会记录在“库存流水”</span>
              </template>
            </el-form-item>
            <el-form-item label="库存预警值"><el-input-number v-model="form.sku.warn_stock" :min="0" :max="99999999" /><span class="hint-inline">库存不高于该值时在列表标红，0 表示不预警</span></el-form-item>
            <el-form-item label="规格编码"><el-input v-model="form.sku.sku_sn" maxlength="60" style="width: 320px" /></el-form-item>
            <el-form-item label="重量（kg）"><el-input-number v-model="form.sku.weight" :min="0" :precision="3" :controls="false" style="width: 200px" /></el-form-item>
            <el-form-item label="体积（m³）"><el-input-number v-model="form.sku.volume" :min="0" :precision="3" :controls="false" style="width: 200px" /></el-form-item>
          </template>

          <!-- 多规格 -->
          <template v-else>
            <el-form-item label="规格设置" required><SpecEditor v-model="form.specs" :languages="languages" /></el-form-item>
            <el-form-item v-if="form.skus.length" label="规格明细" required>
              <SkuTable :rows="form.skus" :specs="form.specs" :languages="languages" :currencies="otherCurrencies" :regions="saleRegions" :base-code="baseCurrency?.code || ''" @adjust="openSkuStock" />
            </el-form-item>
          </template>
        </el-tab-pane>

        <el-tab-pane label="商品详情" name="detail">
          <el-form-item label-width="0"><LangRichText v-model="form.description" :languages="languages" :height="420" ai field-key="description" /></el-form-item>
        </el-tab-pane>

        <el-tab-pane label="SEO" name="seo">
          <el-form-item label="关键词"><LangInput v-model="form.keywords" :languages="languages" :maxlength="255" ai field-key="keywords" /></el-form-item>
          <el-form-item label="SEO 标题"><LangInput v-model="form.seo_title" :languages="languages" :maxlength="255" ai field-key="seo_title" /></el-form-item>
          <el-form-item label="SEO 描述"><LangInput v-model="form.seo_desc" :languages="languages" type="textarea" :maxlength="500" ai field-key="seo_desc" /></el-form-item>
        </el-tab-pane>
      </el-tabs>
    </el-form>

    <template #footer>
      <el-button @click="emit('update:modelValue', false)">取消</el-button>
      <el-button v-if="!isEdit ? hasPerm('goods:list:add') : hasPerm('goods:list:edit')" type="primary" :loading="saving" @click="submit">保存</el-button>
    </template>

    <StockDialog v-model="stockDialog" :goods="stockGoods" :sku="stockSku" @done="onStockDone" />
  </el-drawer>
</template>

<style scoped>
.hint-inline { margin-left: 10px; color: #909399; font-size: 12px; }
.ai-bar { margin-bottom: 12px; padding: 8px 12px; background: #fdf6ec; border-radius: 6px; display: flex; align-items: center; }
.price-grid { display: flex; flex-direction: column; gap: 8px; }
.price-row { display: flex; align-items: center; }
.cur { width: 50px; font-weight: 600; }
.region { width: 120px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
