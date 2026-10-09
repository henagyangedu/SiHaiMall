<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listGoods, deleteGoods, setGoodsSale } from '@/api/goods'
import { categoryTree } from '@/api/category'
import { brandOptions } from '@/api/brand'
import { currencyOptions } from '@/api/option'
import { hasPerm } from '@/utils/perm'
import GoodsForm from './GoodsForm.vue'
import StockDialog from './StockDialog.vue'

const query = reactive({ keyword: '', category_id: null, brand_id: null, is_on_sale: '', low_stock: 0, page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)
const categories = ref([])
const brands = ref([])
const baseCurrency = ref(null)

function simplify(nodes) {
  return nodes.map((n) => ({ id: n.id, name_text: n.name_text, children: simplify(n.children || []) }))
}
const categoryOptions = computed(() => simplify(categories.value))

async function load() {
  loading.value = true
  try {
    const params = { ...query, category_id: query.category_id || '', brand_id: query.brand_id || '', low_stock: query.low_stock ? 1 : '' }
    const d = await listGoods(params)
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
function reset() {
  Object.assign(query, { keyword: '', category_id: null, brand_id: null, is_on_sale: '', low_stock: 0 })
  search()
}

// ---- 新增 / 编辑 ----
const formVisible = ref(false)
const editId = ref(0)
const formTab = ref('base')
function openCreate() {
  editId.value = 0
  formTab.value = 'base'
  formVisible.value = true
}
function openEdit(row) {
  editId.value = row.id
  formTab.value = 'base'
  formVisible.value = true
}

// ---- 上下架 / 删除 / 库存 ----
async function onSale(row, val) {
  try {
    await setGoodsSale(row.id, val)
    ElMessage.success(val === 1 ? '已上架' : '已下架')
  } finally {
    load()
  }
}
async function onDelete(row) {
  await ElMessageBox.confirm(`确定删除商品「${row.title}」吗？`, '提示', { type: 'warning' })
  await deleteGoods(row.id)
  ElMessage.success('已删除')
  load()
}
const stockVisible = ref(false)
const stockGoods = ref(null)
function openStock(row) {
  if (row.spec_type === 2) {
    // 多规格要按规格调整，打开编辑抽屉并停在“价格库存”页签
    editId.value = row.id
    formTab.value = 'price'
    formVisible.value = true
    return
  }
  stockGoods.value = { id: row.id, title: row.title, stock: row.stock }
  stockVisible.value = true
}

onMounted(async () => {
  load()
  try {
    const [cats, bs, curs] = await Promise.all([categoryTree(), brandOptions(), currencyOptions()])
    categories.value = cats
    brands.value = bs
    baseCurrency.value = curs.find((c) => c.is_base === 1) || null
  } catch (e) { /* 下拉数据失败不影响列表 */ }
})
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="标题 / 商品编码 / 规格编码" clearable style="width: 220px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-tree-select v-model="query.category_id" :data="categoryOptions" node-key="id" check-strictly default-expand-all clearable placeholder="全部分类" :props="{ label: 'name_text', children: 'children' }" style="width: 180px" />
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.brand_id" clearable placeholder="全部品牌" style="width: 140px">
            <el-option v-for="b in brands" :key="b.id" :label="b.name" :value="b.id" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.is_on_sale" clearable placeholder="上架状态" style="width: 120px">
            <el-option label="已上架" :value="1" />
            <el-option label="已下架" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item><el-checkbox v-model="query.low_stock" :true-value="1" :false-value="0">仅看库存预警</el-checkbox></el-form-item>
        <el-form-item>
          <el-button type="primary" @click="search">查询</el-button>
          <el-button @click="reset">重置</el-button>
          <el-button v-perm="'goods:list:add'" type="success" @click="openCreate">新增商品</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="商品" min-width="300">
          <template #default="{ row }">
            <div class="goods-cell">
              <el-image v-if="row.cover" :src="row.cover" :preview-src-list="[row.cover]" preview-teleported fit="cover" class="thumb" />
              <div v-else class="thumb empty">无图</div>
              <div class="info">
                <div class="title">{{ row.title }}</div>
                <div class="sub">{{ row.goods_sn }} · {{ row.category_name }}<template v-if="row.brand_name"> · {{ row.brand_name }}</template></div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column :label="`售价（${baseCurrency?.code || ''}）`" width="130">
          <template #default="{ row }">{{ row.price }}<span v-if="row.spec_type === 2" style="color: #909399"> 起</span></template>
        </el-table-column>
        <el-table-column label="库存" width="90">
          <template #default="{ row }"><span :style="{ color: row.low_stock ? '#f56c6c' : '', fontWeight: row.low_stock ? 600 : 400 }">{{ row.stock }}</span></template>
        </el-table-column>
        <el-table-column prop="sales" label="销量" width="80" />
        <el-table-column label="规格" width="80"><template #default="{ row }">{{ row.spec_type === 1 ? '单规格' : '多规格' }}</template></el-table-column>
        <el-table-column label="上架" width="80">
          <template #default="{ row }">
            <el-switch :model-value="row.is_on_sale" :active-value="1" :inactive-value="0" :disabled="!hasPerm('goods:list:sale')" @change="(v) => onSale(row, v)" />
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="70" />
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button v-perm="'goods:list:edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-perm="'goods:list:stock'" link type="warning" @click="openStock(row)">库存</el-button>
            <el-button v-perm="'goods:list:del'" link type="danger" @click="onDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>

    <GoodsForm v-model="formVisible" :id="editId" :init-tab="formTab" @saved="load" />
    <StockDialog v-model="stockVisible" :goods="stockGoods" @done="load" />
  </div>
</template>

<style scoped>
.goods-cell { display: flex; align-items: center; gap: 10px; }
.thumb { width: 52px; height: 52px; border-radius: 4px; flex: none; }
.thumb.empty { background: #f5f7fa; color: #c0c4cc; font-size: 12px; display: flex; align-items: center; justify-content: center; }
.title { font-weight: 500; line-height: 1.4; }
.sub { color: #909399; font-size: 12px; margin-top: 2px; }
</style>
