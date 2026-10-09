<script setup>
import { ref, reactive, onMounted } from 'vue'
import { listLog } from '@/api/log'

const query = reactive({ keyword: '', module: '', status: '', page: 1, size: 20 })
const range = ref([])
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const params = { ...query, date_from: range.value?.[0] || '', date_to: range.value?.[1] || '' }
    const d = await listLog(params)
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
function prettyParams(s) {
  try {
    return JSON.stringify(JSON.parse(s), null, 2)
  } catch (e) {
    return s
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="操作 / 地址关键字" clearable style="width: 200px" @keyup.enter="search" /></el-form-item>
        <el-form-item><el-input v-model="query.module" placeholder="模块，如 admin" clearable style="width: 140px" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="结果" clearable style="width: 110px">
            <el-option label="成功" :value="1" />
            <el-option label="失败" :value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-date-picker v-model="range" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" />
        </el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column type="expand">
          <template #default="{ row }">
            <pre style="margin: 0 24px; white-space: pre-wrap">{{ prettyParams(row.params) }}</pre>
          </template>
        </el-table-column>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column label="操作人" min-width="110">
          <template #default="{ row }">{{ row.admin?.nickname || row.admin?.username || '-' }}</template>
        </el-table-column>
        <el-table-column prop="module" label="模块" width="100" />
        <el-table-column prop="action" label="操作" min-width="190" />
        <el-table-column prop="method" label="方法" width="90" />
        <el-table-column prop="url" label="地址" min-width="220" show-overflow-tooltip />
        <el-table-column label="结果" width="80">
          <template #default="{ row }"><el-tag :type="row.status === 1 ? 'success' : 'danger'">{{ row.status === 1 ? '成功' : '失败' }}</el-tag></template>
        </el-table-column>
        <el-table-column prop="ip" label="IP" width="140" />
        <el-table-column prop="created_at" label="时间" min-width="160" />
      </el-table>

      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[20, 50, 100]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>
  </div>
</template>
