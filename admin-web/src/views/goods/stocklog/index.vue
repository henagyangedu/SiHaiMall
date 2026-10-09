<script setup>
import { ref, reactive, onMounted } from 'vue'
import { listStockLog } from '@/api/goods'

const TYPES = [
  { value: 1, label: '下单扣减' },
  { value: 2, label: '取消回补' },
  { value: 3, label: '售后回补' },
  { value: 4, label: '后台调整' },
  { value: 5, label: '导入' },
]
const query = reactive({ keyword: '', type: '', page: 1, size: 20 })
const range = ref([])
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const d = await listStockLog({ ...query, date_from: range.value?.[0] || '', date_to: range.value?.[1] || '' })
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

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="商品标题 / 商品编码 / 规格编码" clearable style="width: 240px" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.type" clearable placeholder="变动类型" style="width: 130px">
            <el-option v-for="t in TYPES" :key="t.value" :label="t.label" :value="t.value" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-date-picker v-model="range" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" />
        </el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="goods_title" label="商品" min-width="220" show-overflow-tooltip />
        <el-table-column prop="sku_sn" label="规格编码" width="130" />
        <el-table-column prop="type_text" label="类型" width="100" />
        <el-table-column label="变动" width="90">
          <template #default="{ row }"><b :style="{ color: row.change_qty > 0 ? '#67c23a' : '#f56c6c' }">{{ row.change_qty > 0 ? '+' : '' }}{{ row.change_qty }}</b></template>
        </el-table-column>
        <el-table-column prop="after_qty" label="变动后库存" width="110" />
        <el-table-column prop="ref_no" label="关联单号" width="150" />
        <el-table-column prop="operator" label="操作人" width="110" />
        <el-table-column prop="remark" label="备注" min-width="160" show-overflow-tooltip />
        <el-table-column prop="created_at" label="时间" width="170" />
      </el-table>

      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[20, 50, 100]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>
  </div>
</template>
