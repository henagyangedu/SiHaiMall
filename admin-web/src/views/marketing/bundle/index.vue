<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listBundle, bundleSkuOptions, createBundle, updateBundle, setBundleStatus, deleteBundle } from '@/api/bundle'

// 搭配套餐：把 2 到 6 个具体规格的商品配成一组，买家的订单里凑齐这一组就立减（凑齐几套减几次）
const PERIOD = { pending: ['未开始', 'info'], running: ['进行中', 'success'], ended: ['已结束', 'info'] }
const MAX = 6

const query = reactive({ keyword: '', status: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const d = await listBundle(query)
    list.value = d.list
    total.value = d.total
  } finally {
    loading.value = false
  }
}
function search() {
  query.page = 1
  load()
}

// ---- 新建 / 编辑
const emptyForm = () => ({ id: 0, name: '', reduce_amount: undefined, range: null, status: 1, items: [] })
const form = reactive(emptyForm())
const dialog = ref(false)
const saving = ref(false)
const sum = computed(() => form.items.reduce((s, i) => s + Number(i.price), 0))
const after = computed(() => (form.reduce_amount ? sum.value - Number(form.reduce_amount) : sum.value))

function openCreate() {
  Object.assign(form, emptyForm())
  picker.keyword = ''
  picker.options = []
  dialog.value = true
}
function openEdit(r) {
  Object.assign(form, emptyForm(), {
    id: r.id, name: r.name, reduce_amount: Number(r.reduce_amount), status: r.status,
    range: r.start_at || r.end_at ? [r.start_at, r.end_at] : null,
    items: r.items.filter((i) => i.valid).map((i) => ({ ...i })),
  })
  picker.keyword = ''
  picker.options = []
  dialog.value = true
}

// 选商品：按名称搜索，把规格平铺出来点选
const picker = reactive({ keyword: '', options: [], loading: false })
async function searchSkus() {
  picker.loading = true
  try {
    picker.options = await bundleSkuOptions(picker.keyword.trim())
  } finally {
    picker.loading = false
  }
}
const picked = (id) => form.items.some((i) => i.sku_id === id)
function pick(s) {
  if (picked(s.sku_id)) return
  if (form.items.length >= MAX) return ElMessage.warning(`一个套餐最多 ${MAX} 件商品`)
  form.items.push({ ...s })
}

async function submit() {
  if (!form.name.trim()) return ElMessage.error('请填写套餐名称')
  if (form.items.length < 2) return ElMessage.error('至少选 2 件商品')
  if (!form.reduce_amount) return ElMessage.error('请填写立减金额')
  if (Number(form.reduce_amount) >= sum.value) return ElMessage.error('立减金额必须小于这组商品的售价合计')
  const payload = {
    name: form.name.trim(), reduce_amount: form.reduce_amount, status: form.status, sku_ids: form.items.map((i) => i.sku_id),
    start_at: form.range?.[0] || null, end_at: form.range?.[1] || null,
  }
  saving.value = true
  try {
    if (form.id) await updateBundle(form.id, payload)
    else await createBundle(payload)
    ElMessage.success('保存成功')
    dialog.value = false
    load()
  } finally {
    saving.value = false
  }
}

async function toggle(r) {
  await setBundleStatus(r.id, r.status === 1 ? 0 : 1)
  ElMessage.success(r.status === 1 ? '已停用' : '已启用')
  load()
}
async function remove(r) {
  await ElMessageBox.confirm(`确定删除套餐「${r.name}」吗？删除后新订单不再享受这个优惠，已经下的订单不受影响。`, '提示', { type: 'warning' })
  await deleteBundle(r.id)
  ElMessage.success('已删除')
  load()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px"
        title="买家的订单里凑齐套餐里的每一件商品（各 1 件算一套）就自动立减，凑齐几套减几次；可以和会员折扣、优惠券、积分一起用，优惠券的门槛按套餐优惠后的金额算。只用于人民币订单。套餐里的商品退掉一件时，套餐优惠不追回。" />
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="套餐名称" clearable style="width: 200px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="状态" clearable style="width: 110px" @change="search">
            <el-option label="启用" :value="1" /><el-option label="停用" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
        <el-form-item><el-button v-perm="'marketing:bundle:add'" type="success" @click="openCreate">新建套餐</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="套餐名称" min-width="160" />
        <el-table-column label="套餐商品" min-width="360">
          <template #default="{ row }">
            <div v-for="i in row.items" :key="i.sku_id" class="line" :class="{ off: !i.valid }">
              {{ i.title }}<span v-if="i.spec_text" class="sub">（{{ i.spec_text }}）</span><span class="sub">　{{ i.price }}</span>
            </div>
            <div v-if="row.problem" class="problem">{{ row.problem }}</div>
          </template>
        </el-table-column>
        <el-table-column label="价格" width="170">
          <template #default="{ row }">
            <div class="sub">原价合计 {{ row.total }}</div>
            <div>套餐价 <b>{{ row.bundle_price }}</b></div>
            <div class="reduce">立减 {{ row.reduce_amount }}</div>
          </template>
        </el-table-column>
        <el-table-column label="时间" width="180">
          <template #default="{ row }">
            <template v-if="row.start_at || row.end_at">
              <div class="sub">{{ row.start_at || '不限' }} 起</div>
              <div class="sub">{{ row.end_at || '不限' }} 止</div>
            </template>
            <span v-else class="sub">长期有效</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag v-if="row.status !== 1" type="info">已停用</el-tag>
            <el-tag v-else :type="PERIOD[row.period][1]">{{ PERIOD[row.period][0] }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="170" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'marketing:bundle:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-perm="'marketing:bundle:edit'" link :type="row.status === 1 ? 'warning' : 'success'" @click="toggle(row)">{{ row.status === 1 ? '停用' : '启用' }}</el-button>
            <el-button v-perm="'marketing:bundle:del'" link type="danger" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <el-dialog v-model="dialog" :title="form.id ? '编辑套餐' : '新建套餐'" width="760px" destroy-on-close>
      <el-form :model="form" label-width="90px">
        <el-form-item label="套餐名称" required>
          <el-input v-model="form.name" maxlength="100" placeholder="只在后台显示，方便自己辨认，比如“喂食器 + 滤芯”" />
        </el-form-item>
        <el-form-item label="套餐商品" required>
          <div class="picked">
            <div v-for="(i, k) in form.items" :key="i.sku_id" class="picked-row">
              <span class="ellipsis">{{ i.title }}<span v-if="i.spec_text" class="sub">（{{ i.spec_text }}）</span></span>
              <span class="sub">{{ i.price }}</span>
              <el-button link type="danger" @click="form.items.splice(k, 1)">移除</el-button>
            </div>
            <div v-if="!form.items.length" class="sub">还没有选商品。在下面搜索后点选，选 2 到 6 件，每件是一个具体的规格。</div>
          </div>
          <div class="search">
            <el-input v-model="picker.keyword" placeholder="按商品名称或货号搜索" clearable style="width: 300px" @keyup.enter="searchSkus" />
            <el-button :loading="picker.loading" @click="searchSkus">搜索</el-button>
          </div>
          <div v-if="picker.options.length" class="options">
            <div v-for="s in picker.options" :key="s.sku_id" class="option" :class="{ on: picked(s.sku_id) }" @click="pick(s)">
              <span class="ellipsis">{{ s.title }}<span v-if="s.spec_text" class="sub">（{{ s.spec_text }}）</span></span>
              <span class="sub">{{ s.price }}　库存 {{ s.stock }}</span>
              <span class="add">{{ picked(s.sku_id) ? '已选' : '选择' }}</span>
            </div>
          </div>
        </el-form-item>
        <el-form-item label="立减金额" required>
          <el-input-number v-model="form.reduce_amount" :min="0.01" :precision="2" :controls="false" />
          <span class="unit">元。原价合计 {{ sum.toFixed(2) }}，套餐价 <b>{{ after.toFixed(2) }}</b></span>
        </el-form-item>
        <el-form-item label="有效时间">
          <el-date-picker v-model="form.range" type="datetimerange" value-format="YYYY-MM-DD HH:mm:ss" start-placeholder="开始时间" end-placeholder="结束时间" />
          <span class="unit">不填就是长期有效</span>
        </el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.sub { color: #909399; font-size: 12px; }
.unit { margin-left: 10px; color: #909399; font-size: 13px; }
.line { line-height: 1.8; }
.line.off { text-decoration: line-through; color: #c0c4cc; }
.problem { color: #e6a23c; font-size: 12px; }
.reduce { color: #f56c6c; font-size: 12px; }
.picked { width: 100%; padding: 8px 12px; background: #f5f7fa; border-radius: 4px; }
.picked-row, .option { display: flex; align-items: center; gap: 12px; line-height: 2; }
.picked-row .ellipsis, .option .ellipsis { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.search { display: flex; gap: 8px; margin-top: 10px; width: 100%; }
.options { width: 100%; max-height: 220px; overflow-y: auto; margin-top: 8px; border: 1px solid #ebeef5; border-radius: 4px; }
.option { padding: 0 12px; cursor: pointer; border-bottom: 1px solid #f2f3f5; }
.option:last-child { border-bottom: none; }
.option:hover { background: #f5f7fa; }
.option.on { color: #c0c4cc; cursor: default; }
.add { color: var(--el-color-primary); font-size: 13px; flex: none; }
.option.on .add { color: #c0c4cc; }
</style>
