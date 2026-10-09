<script setup>
import { ref, reactive, onMounted } from 'vue'
import { listPointLog } from '@/api/member'

const query = reactive({ keyword: '', type: '', range: [], page: 1, size: 20 })
const list = ref([])
const total = ref(0)
const loading = ref(false)
const types = [[1, '注册奖励'], [2, '签到'], [3, '消费获得'], [4, '下单抵扣'], [5, '后台调整'], [6, '退款扣回']]

async function load() {
  loading.value = true
  try {
    const [from, to] = query.range || []
    const d = await listPointLog({ keyword: query.keyword, type: query.type, date_from: from || '', date_to: to || '', page: query.page, size: query.size })
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
        <el-form-item><el-input v-model="query.keyword" placeholder="会员ID / 昵称 / 手机 / 邮箱" clearable style="width: 240px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.type" placeholder="类型" clearable style="width: 130px" @change="search">
            <el-option v-for="t in types" :key="t[0]" :label="t[1]" :value="t[0]" />
          </el-select>
        </el-form-item>
        <el-form-item><el-date-picker v-model="query.range" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" @change="search" /></el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column label="会员" min-width="160"><template #default="{ row }">{{ row.member }}<span style="color: #909399"> (#{{ row.member_id }})</span></template></el-table-column>
        <el-table-column prop="type_text" label="类型" width="110" />
        <el-table-column label="变动" width="110">
          <template #default="{ row }"><span :style="{ color: row.change_points > 0 ? '#67c23a' : '#f56c6c', fontWeight: 600 }">{{ row.change_points > 0 ? '+' : '' }}{{ row.change_points }}</span></template>
        </el-table-column>
        <el-table-column prop="after_points" label="变动后" width="100" />
        <el-table-column prop="remark" label="备注" min-width="180" show-overflow-tooltip />
        <el-table-column prop="operator" label="操作人" width="110" />
        <el-table-column prop="created_at" label="时间" width="170" />
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[20, 50, 100]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>
  </div>
</template>
