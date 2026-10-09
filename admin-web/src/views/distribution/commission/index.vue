<script setup>
import { ref, reactive, onMounted } from 'vue'
import { listCommission } from '@/api/distribution'

// 佣金状态：0待结算 1已结算 2已作废
const query = reactive({ keyword: '', status: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const d = await listCommission(query)
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
const statusType = (s) => ({ 0: 'warning', 1: 'success', 2: 'info' })[s] || 'info'

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="订单号 / 分销员昵称、账号、会员 ID" clearable style="width: 280px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="全部状态" clearable style="width: 130px" @change="search">
            <el-option label="待结算" :value="0" /><el-option label="已结算" :value="1" /><el-option label="已作废" :value="2" />
          </el-select>
        </el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="order_no" label="订单号" min-width="190" />
        <el-table-column label="分销员" min-width="150"><template #default="{ row }">{{ row.member }}<span class="sub">　ID {{ row.member_id }}</span></template></el-table-column>
        <el-table-column prop="buyer" label="买家" min-width="130" />
        <el-table-column label="层级" width="80"><template #default="{ row }">{{ row.level === 1 ? '一级' : '二级' }}</template></el-table-column>
        <el-table-column prop="base_amount" label="计佣基数" width="110" />
        <el-table-column label="比例" width="80"><template #default="{ row }">{{ Number(row.rate) }}%</template></el-table-column>
        <el-table-column label="佣金" width="110"><template #default="{ row }"><b>{{ row.amount }}</b></template></el-table-column>
        <el-table-column label="状态" width="100"><template #default="{ row }"><el-tag :type="statusType(row.status)">{{ row.status_text }}</el-tag></template></el-table-column>
        <el-table-column prop="created_at" label="生成时间" min-width="160" />
        <el-table-column prop="settled_at" label="结算时间" min-width="160" />
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
      <div class="sub" style="margin-top: 8px">金额均为基准币种。佣金在退款后会按已退商品款的占比减少；“比例 × 基数”是原始佣金，“佣金”列是当前实际金额。</div>
    </el-card>
  </div>
</template>

<style scoped>
.sub { color: #909399; font-size: 12px; }
</style>
