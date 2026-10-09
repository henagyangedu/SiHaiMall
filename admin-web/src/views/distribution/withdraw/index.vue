<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { listWithdraw, auditWithdraw, payWithdraw } from '@/api/distribution'
import { hasPerm } from '@/utils/perm'

// 提现状态：0待审核 1待打款 2已打款 3已拒绝
const ACCOUNT_LABELS = { name: '收款人', no: '账号', bank: '开户行' }
const query = reactive({ keyword: '', status: '', page: 1, size: 15 })
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const d = await listWithdraw(query)
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
const statusType = (s) => ({ 0: 'warning', 1: 'primary', 2: 'success', 3: 'info' })[s] || 'info'
const accountLines = (info) => Object.entries(info || {}).filter(([, v]) => v).map(([k, v]) => `${ACCOUNT_LABELS[k] || k}：${v}`)

async function onPass(row) {
  await ElMessageBox.confirm(`通过「${row.member}」的提现申请 ${row.amount}？通过后请线下打款，再回来登记“已打款”。`, '审核通过', { type: 'warning' })
  await auditWithdraw(row.id, { pass: 1 })
  ElMessage.success('已通过，等待打款')
  load()
}
async function onReject(row) {
  const { value } = await ElMessageBox.prompt(`驳回「${row.member}」的提现 ${row.amount}，金额会退回可提现佣金。请填写原因（申请人可见）`, '驳回提现', { inputPattern: /^[\s\S]{1,255}$/, inputErrorMessage: '请填写 1 到 255 字的原因' })
  await auditWithdraw(row.id, { pass: 0, reject_reason: value })
  ElMessage.success('已驳回')
  load()
}
async function onPaid(row) {
  const { value } = await ElMessageBox.prompt(`确认已向「${row.member}」打款 ${row.amount}？登记后不可撤销。可填写打款流水号或凭证说明`, '登记打款', { inputPattern: /^[\s\S]{0,255}$/, inputErrorMessage: '最多 255 字', confirmButtonText: '确认已打款', type: 'warning' })
  await payWithdraw(row.id, value || '')
  ElMessage.success('已登记打款')
  load()
}

onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="提现单号 / 分销员昵称、账号、会员 ID" clearable style="width: 290px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="全部状态" clearable style="width: 130px" @change="search">
            <el-option label="待审核" :value="0" /><el-option label="待打款" :value="1" /><el-option label="已打款" :value="2" /><el-option label="已拒绝" :value="3" />
          </el-select>
        </el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="withdraw_no" label="提现单号" min-width="190" />
        <el-table-column label="分销员" min-width="150"><template #default="{ row }">{{ row.member }}<span class="sub">　ID {{ row.member_id }}</span></template></el-table-column>
        <el-table-column label="金额" width="110"><template #default="{ row }"><b>{{ row.amount }}</b></template></el-table-column>
        <el-table-column label="收款信息" min-width="240">
          <template #default="{ row }"><div>{{ row.method_text }}</div><div v-for="(l, i) in accountLines(row.account_info)" :key="i" class="sub">{{ l }}</div></template>
        </el-table-column>
        <el-table-column label="状态" min-width="150">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)">{{ row.status_text }}</el-tag>
            <div v-if="row.reject_reason" class="sub">{{ row.reject_reason }}</div>
            <div v-if="row.pay_remark" class="sub">{{ row.pay_remark }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="申请时间" min-width="160" />
        <el-table-column prop="paid_at" label="打款时间" min-width="160" />
        <el-table-column prop="operator" label="处理人" width="100" />
        <el-table-column label="操作" width="170" fixed="right">
          <template #default="{ row }">
            <template v-if="hasPerm('distribution:withdraw:audit')">
              <el-button v-if="row.status === 0" link type="primary" @click="onPass(row)">通过</el-button>
              <el-button v-if="[0, 1].includes(row.status)" link type="danger" @click="onReject(row)">驳回</el-button>
            </template>
            <el-button v-if="row.status === 1 && hasPerm('distribution:withdraw:paid')" link type="success" @click="onPaid(row)">登记打款</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[15, 30, 50]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
      <div class="sub" style="margin-top: 8px">金额为基准币种。系统不会自动转账：审核通过后请线下打款，再登记“已打款”。</div>
    </el-card>
  </div>
</template>

<style scoped>
.sub { color: #909399; font-size: 12px; }
</style>
