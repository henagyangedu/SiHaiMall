<script setup>
import { ref, reactive, onMounted } from 'vue'
import { listTrade } from '@/api/pay'

const query = reactive({ keyword: '', channel: '', status: '', trade_type: '', flagged: '', range: [], page: 1, size: 20 })
const list = ref([])
const total = ref(0)
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const [from, to] = query.range || []
    const d = await listTrade({ keyword: query.keyword, channel: query.channel, status: query.status, trade_type: query.trade_type, flagged: query.flagged, date_from: from || '', date_to: to || '', page: query.page, size: query.size })
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
const statusType = (s) => ({ 0: 'warning', 1: 'success', 2: 'danger', 3: 'info' })[s] || 'info'
onMounted(load)
</script>

<template>
  <div class="page">
    <el-card shadow="never">
      <el-form :inline="true" @submit.prevent="search">
        <el-form-item><el-input v-model="query.keyword" placeholder="支付单号 / 订单号 / 渠道交易号" clearable style="width: 260px" @clear="search" @keyup.enter="search" /></el-form-item>
        <el-form-item>
          <el-select v-model="query.channel" placeholder="渠道" clearable style="width: 120px" @change="search">
            <el-option label="Stripe" value="stripe" /><el-option label="PayPal" value="paypal" /><el-option label="支付宝" value="alipay" /><el-option label="微信" value="wechat" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-select v-model="query.status" placeholder="状态" clearable style="width: 110px" @change="search">
            <el-option label="待支付" :value="0" /><el-option label="成功" :value="1" /><el-option label="失败" :value="2" /><el-option label="已关闭" :value="3" />
          </el-select>
        </el-form-item>
        <el-form-item><el-checkbox v-model="query.flagged" :true-value="1" :false-value="''" @change="search">只看需人工处理</el-checkbox></el-form-item>
        <el-form-item><el-date-picker v-model="query.range" type="daterange" value-format="YYYY-MM-DD" range-separator="至" start-placeholder="创建开始" end-placeholder="创建结束" @change="search" /></el-form-item>
        <el-form-item><el-button type="primary" @click="search">查询</el-button></el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="list" border stripe>
        <el-table-column prop="trade_no" label="支付单号" min-width="190" />
        <el-table-column prop="order_no" label="订单号" min-width="190" />
        <el-table-column prop="channel" label="渠道" width="90" />
        <el-table-column prop="trade_type_text" label="类型" width="70" />
        <el-table-column label="金额" width="140"><template #default="{ row }"><b>{{ row.amount }}</b> {{ row.currency }}</template></el-table-column>
        <el-table-column prop="fee_amount" label="手续费" width="90" />
        <el-table-column label="状态" min-width="160">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)">{{ row.status_text }}</el-tag>
            <el-tag v-if="row.flag" type="danger" effect="dark" style="margin-left: 6px" :title="row.flag">需人工处理</el-tag>
            <span v-if="row.flag" style="color: #f56c6c; font-size: 12px; display: block">{{ row.flag }}</span>
            <span v-else-if="row.error" style="color: #f56c6c; font-size: 12px; margin-left: 6px">{{ row.error }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="channel_trade_no" label="渠道交易号" min-width="200" show-overflow-tooltip />
        <el-table-column prop="paid_at" label="支付时间" width="165" />
        <el-table-column prop="created_at" label="创建时间" width="165" />
      </el-table>
      <div class="pager">
        <el-pagination v-model:current-page="query.page" v-model:page-size="query.size" :total="total" :page-sizes="[20, 50, 100]" layout="total, sizes, prev, pager, next" @current-change="load" @size-change="search" />
      </div>
    </el-card>
  </div>
</template>
